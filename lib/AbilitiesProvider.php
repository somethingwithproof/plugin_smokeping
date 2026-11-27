<?php
/*
 * SPDX-FileCopyrightText: 2026 Thomas Vincent <thomasvincent@gmail.com>
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * This file is part of plugin_smokeping, a plugin for Cacti.
 * https://github.com/somethingwithproof/plugin_smokeping
 *
 * This program is free software; you can redistribute it and/or modify it
 * under the terms of the GNU General Public License as published by the Free
 * Software Foundation; either version 2 of the License, or (at your option)
 * any later version.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
 * FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for
 * more details.
 */

namespace Somethingwithproof\Smokeping;

/**
 * Registers strictly read-only SmokePing abilities through the
 * abilities_register hook. All permissions are evaluated against the token
 * owner's user ID (never session state), gated on the SmokePing View realm,
 * and return zero secrets or credentials.
 */
final class AbilitiesProvider {
	/** Category constant. */
	public const CATEGORY            = 'smokeping';
	/** Ability list constant. */
	public const ABILITY_LIST        = 'smokeping-list-targets';
	/** Ability get health constant. */
	public const ABILITY_GET_HEALTH  = 'smokeping-get-health';
	/** Max target results constant. */
	private const MAX_TARGET_RESULTS = 200;

	/**
	 * @param \Closure(int): bool           $hasViewRealm
	 * @param \Closure(int): array<int, string> $allowedDevices userId => [hostId => description]
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly TargetRepository $targets,
		private readonly ObservationRepository $observations,
		private readonly HealthRepository $health,
		private readonly FreshnessEvaluator $evaluator,
		private readonly Clock $clock,
		private readonly int $pollerInterval,
		private readonly \Closure $hasViewRealm,
		private readonly \Closure $allowedDevices,
	) {
	}

	/**
	 * Register category and read-only abilities with an AbilitiesRegistrar.
	 */
	public function register(object $registrar): void {
		if (method_exists($registrar, 'registerCategory')) {
			$registrar->registerCategory(self::CATEGORY, 'SmokePing');
		}

		if (!method_exists($registrar, 'register')) {
			return;
		}

		$registrar->register(self::ABILITY_LIST, $this->listTargetsDefinition());
		$registrar->register(self::ABILITY_GET_HEALTH, $this->getHealthDefinition());
	}

	/**
	 * @return array<string, mixed>
	 */
	public function listTargetsDefinition(): array {
		return [
			'label'        => 'List SmokePing Targets',
			'description'  => 'List SmokePing targets mapped to Cacti devices the caller is allowed to view, with latest freshness, median latency, and packet loss.',
			'category'     => self::CATEGORY,
			'input_schema' => [
				'type'                 => 'object',
				'properties'           => [
					'search' => ['type' => 'string', 'maxLength' => 200],
					'probe'  => ['type' => 'string', 'maxLength' => 64],
					'limit'  => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_TARGET_RESULTS],
				],
				'additionalProperties' => false,
			],
			'output_schema' => [
				'type'                 => 'object',
				'properties'           => [
					'total'   => ['type' => 'integer', 'minimum' => 0],
					'targets' => [
						'type'     => 'array',
						'maxItems' => self::MAX_TARGET_RESULTS,
						'items'    => [
							'type'                 => 'object',
							'properties'           => [
								'path'        => ['type' => 'string'],
								'name'        => ['type' => 'string'],
								'host'        => ['type' => 'string'],
								'probe'       => ['type' => 'string'],
								'device'      => ['type' => 'string'],
								'location'    => ['type' => 'string'],
								'freshness'   => ['type' => 'string', 'enum' => ['current', 'delayed', 'stale', 'never']],
								'condition'   => ['type' => 'string', 'enum' => ['within_policy', 'degraded', 'complete_loss', 'unknown']],
								'median_ms'   => ['type' => ['number', 'null']],
								'loss_pct'    => ['type' => ['number', 'null']],
								'age_seconds' => ['type' => ['integer', 'null']],
							],
							'required'             => ['path', 'name', 'host', 'probe', 'device', 'location', 'freshness', 'condition', 'median_ms', 'loss_pct', 'age_seconds'],
							'additionalProperties' => false,
						],
					],
				],
				'required'             => ['total', 'targets'],
				'additionalProperties' => false,
			],
			'annotations' => [
				'readOnly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			],
			'permission' => fn (int $userId): bool => $this->canView($userId),
			'execute'    => fn (int $userId, \stdClass $args): array => $this->executeListTargets($userId, $args),
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	public function getHealthDefinition(): array {
		return [
			'label'        => 'Get SmokePing Health',
			'description'  => 'Return read-only SmokePing reader, inventory, and target freshness health summary for the configured poller.',
			'category'     => self::CATEGORY,
			'input_schema' => [
				'type'                 => 'object',
				'properties'           => new \stdClass(),
				'additionalProperties' => false,
			],
			'output_schema' => [
				'type'                 => 'object',
				'properties'           => [
					'poller_id'           => ['type' => 'integer', 'minimum' => 1],
					'status'              => ['type' => 'string', 'enum' => ['ok', 'warning', 'error', 'unknown']],
					'inventory_status'    => ['type' => 'string', 'enum' => ['never', 'ok', 'error']],
					'inventory_targets'   => ['type' => 'integer', 'minimum' => 0],
					'series_total'        => ['type' => 'integer', 'minimum' => 0],
					'series_read'         => ['type' => 'integer', 'minimum' => 0],
					'budget_overruns'     => ['type' => 'integer', 'minimum' => 0],
					'last_run_age'        => ['type' => ['integer', 'null']],
					'freshness_breaches'  => ['type' => 'integer', 'minimum' => 0],
					'stale_series'        => ['type' => 'integer', 'minimum' => 0],
				],
				'required'             => ['poller_id', 'status', 'inventory_status', 'inventory_targets', 'series_total', 'series_read', 'budget_overruns', 'last_run_age', 'freshness_breaches', 'stale_series'],
				'additionalProperties' => false,
			],
			'annotations' => [
				'readOnly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			],
			'permission' => fn (int $userId): bool => $this->canView($userId),
			'execute'    => fn (int $userId, \stdClass $args): array => $this->executeGetHealth($userId),
		];
	}

	/**
	 * Can view.
	 *
	 * @param int $userId User id.
	 * @return bool
	 */
	private function canView(int $userId): bool {
		return $userId > 0 && ($this->hasViewRealm)($userId) === true;
	}

	/**
	 * @return array{total: int, targets: list<array<string, mixed>>}
	 */
	public function executeListTargets(int $userId, \stdClass $args): array {
		if (!$this->canView($userId)) {
			return ['total' => 0, 'targets' => []];
		}

		$devices = ($this->allowedDevices)($userId);

		if ($devices === []) {
			return ['total' => 0, 'targets' => []];
		}

		$search = isset($args->search) && is_string($args->search) ? strtolower(trim($args->search)) : '';
		$probe  = isset($args->probe) && is_string($args->probe) ? trim($args->probe) : '';
		$limit  = isset($args->limit) && is_int($args->limit) ? max(1, min(self::MAX_TARGET_RESULTS, $args->limit)) : self::MAX_TARGET_RESULTS;

		$mapped       = $this->targets->mappedTo(array_keys($devices));
		$observations = $this->observations->forTargets(array_map(static fn (array $m): int => (int) $m['target']->id?->value, $mapped));
		$calculator   = new SummaryCalculator($this->evaluator, $this->clock);
		$rows         = [];

		foreach ($mapped as $entry) {
			$target     = $entry['target'];
			$deviceName = $devices[$entry['host_id']] ?? '';

			if ($probe !== '' && $target->probe !== $probe) {
				continue;
			}

			if ($search !== '') {
				$haystack = strtolower($target->path->value . ' ' . $target->displayName() . ' ' . $target->host . ' ' . $deviceName);

				if (!str_contains($haystack, $search)) {
					continue;
				}
			}

			foreach ($target->locations() as $location) {
				$observation = null;

				foreach ($observations[$target->id?->value] ?? [] as $candidate) {
					if ($candidate->location === $location) {
						$observation = $candidate;
					}
				}

				$summary   = $calculator->compute($observation, $target->firstSeen);
				$freshness = $observation === null ? Freshness::Never : $this->evaluator->freshness($observation);
				$condition = $observation === null ? Condition::Unknown : $this->evaluator->condition($observation);

				$rows[] = [
					'path'        => $target->path->value,
					'name'        => $target->displayName(),
					'host'        => $target->host,
					'probe'       => $target->probe,
					'device'      => $deviceName,
					'location'    => $location === '' ? 'master' : $location,
					'freshness'   => $freshness->value,
					'condition'   => $condition->value,
					'median_ms'   => $summary->median,
					'loss_pct'    => $summary->lossPct,
					'age_seconds' => $summary->age,
				];
			}
		}

		$total = count($rows);

		return [
			'total'   => $total,
			'targets' => array_slice($rows, 0, $limit),
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	public function executeGetHealth(int $userId): array {
		$pollerId = $this->settings->pollerId;

		if (!$this->canView($userId)) {
			return [
				'poller_id'          => $pollerId,
				'status'             => HealthLevel::Unknown->value,
				'inventory_status'   => InventoryStatus::Never->value,
				'inventory_targets'  => 0,
				'series_total'       => 0,
				'series_read'        => 0,
				'budget_overruns'    => 0,
				'last_run_age'       => null,
				'freshness_breaches' => 0,
				'stale_series'       => 0,
			];
		}

		$health   = $this->health->forPoller($pollerId);
		$devices  = ($this->allowedDevices)($userId);
		$mapped   = $devices === [] ? [] : $this->targets->mappedTo(array_keys($devices));
		$obsMap   = $this->observations->forTargets(array_map(static fn (array $m): int => (int) $m['target']->id?->value, $mapped));
		$breaches = 0;
		$stale    = 0;

		foreach ($mapped as $entry) {
			$target = $entry['target'];

			foreach ($target->locations() as $location) {
				$obs = null;

				foreach ($obsMap[$target->id?->value] ?? [] as $candidate) {
					if ($candidate->location === $location) {
						$obs = $candidate;
					}
				}

				$fresh = $obs === null ? Freshness::Never : $this->evaluator->freshness($obs);

				if ($fresh === Freshness::Delayed || $fresh === Freshness::Stale || $fresh === Freshness::Never) {
					$breaches++;
				}

				if ($fresh === Freshness::Stale || $fresh === Freshness::Never) {
					$stale++;
				}
			}
		}

		$lastRunAge = $health->runFinished > 0 ? max(0, $this->clock->now() - $health->runFinished) : null;
		$status     = HealthLevel::Ok;

		if ($health->inventoryStatus === InventoryStatus::Error) {
			$status = HealthLevel::Error;
		} elseif ($health->runFinished === 0 || $health->inventoryStatus === InventoryStatus::Never) {
			$status = HealthLevel::Unknown;
		} elseif (($lastRunAge !== null && $lastRunAge > 3 * $this->pollerInterval) || $health->budgetOverruns > 0 || $stale > 0) {
			$status = HealthLevel::Warning;
		}

		return [
			'poller_id'          => $pollerId,
			'status'             => $status->value,
			'inventory_status'   => $health->inventoryStatus->value,
			'inventory_targets'  => $health->inventoryTargets,
			'series_total'       => $health->seriesTotal,
			'series_read'        => $health->seriesRead,
			'budget_overruns'    => $health->budgetOverruns,
			'last_run_age'       => $lastRunAge,
			'freshness_breaches' => $breaches,
			'stale_series'       => $stale,
		];
	}
}
