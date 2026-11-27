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
 * Diagnostic checks exposed through the doctor_register_checks hook.
 *
 * Reports stale targets, orphan RRD files under the mount, freshness breaches,
 * and SmokePing configuration and mount path health in Cacti Doctor's result
 * shape without mutating any state.
 */
final class DoctorChecks {
	/** Doctor check category slug for SmokePing. */
	public const CATEGORY = 'smokeping';

	/**
	 * Create a DoctorChecks runner for SmokePing diagnostic checks and debug snapshots.
	 *
	 * @param Settings $settings Active plugin settings.
	 * @param TargetRepository $targets Repository of SmokePing targets.
	 * @param ObservationRepository $observations Repository of latest RRD observations.
	 * @param FreshnessEvaluator $evaluator Freshness policy evaluator.
	 * @param MountCheck $mountCheck Mount containment and read-only verifier.
	 * @param OrphanRrdScanner $orphanScanner Unowned RRD file scanner.
	 * @param string $rraPath Configured Cacti RRA root path.
	 * @param bool $readerHost Whether the current host is the configured SmokePing reader poller.
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly TargetRepository $targets,
		private readonly ObservationRepository $observations,
		private readonly FreshnessEvaluator $evaluator,
		private readonly MountCheck $mountCheck,
		private readonly OrphanRrdScanner $orphanScanner,
		private readonly string $rraPath,
		private readonly bool $readerHost,
	) {
	}

	/**
	 * Run all SmokePing diagnostic checks and return Doctor-formatted results.
	 *
	 * @return list<array{
	 *     id: string,
	 *     category: string,
	 *     status: string,
	 *     summary: string,
	 *     detail: string,
	 *     repair: string,
	 *     repairable: bool,
	 *     assessment: string
	 * }>
	 */
	public function run(): array {
		$pollerId = $this->settings->pollerId;
		$targets  = $this->targets->readable($pollerId);
		$obsMap   = $this->observations->forTargets(array_values(array_filter(array_map(static fn (Target $t): int => (int) $t->id?->value, $targets), static fn (int $id): bool => $id > 0)));

		$totalSeries   = 0;
		$staleTargets  = [];
		$breachedCount = 0;
		$expectedFiles = [];

		foreach ($targets as $target) {
			foreach ($target->locations() as $location) {
				$totalSeries++;
				$expectedFiles[] = $target->path->rrdFile($this->settings->mountPath, $location);
				$obs             = null;

				foreach ($obsMap[$target->id?->value] ?? [] as $candidate) {
					if ($candidate->location === $location) {
						$obs = $candidate;
					}
				}

				$fresh = $obs === null ? Freshness::Never : $this->evaluator->freshness($obs);

				if ($fresh === Freshness::Stale || $fresh === Freshness::Never) {
					$staleTargets[] = $location === '' ? $target->path->value : $target->path->value . '~' . $location;
				}

				if ($fresh === Freshness::Delayed || $fresh === Freshness::Stale) {
					$breachedCount++;
				}
			}
		}

		$results = [
			$this->staleTargetsResult($totalSeries, $staleTargets),
			$this->freshnessBreachesResult($totalSeries, $breachedCount),
			$this->orphanRrdsResult($expectedFiles),
			$this->configPathResult($expectedFiles),
		];

		return $results;
	}

	/**
	 * Attach SmokePing checks to either an array accumulator or a registrar object.
	 *
	 * @param mixed $registry
	 * @return mixed
	 */
	public function register($registry) {
		$results = $this->run();

		if (is_array($registry)) {
			foreach ($results as $entry) {
				$registry[] = $entry;
			}

			return $registry;
		}

		if (is_object($registry) && method_exists($registry, 'register')) {
			foreach ($results as $entry) {
				$registry->register($entry['id'], $entry);
			}
		}

		return $registry;
	}

	/**
	 * @param list<string> $staleTargets
	 * @return array{id: string, category: string, status: string, summary: string, detail: string, repair: string, repairable: bool, assessment: string}
	 */
	private function staleTargetsResult(int $totalSeries, array $staleTargets): array {
		if ($totalSeries === 0) {
			return self::result('smokeping_stale_targets', 'unknown', 'No SmokePing target series imported', 'No readable SmokePing target series exist on poller ' . $this->settings->pollerId . '.');
		}

		if ($staleTargets === []) {
			return self::result('smokeping_stale_targets', 'ok', 'All SmokePing target series are active', sprintf('All %d series on poller %d have recent observations.', $totalSeries, $this->settings->pollerId));
		}

		$sample = implode(', ', array_slice($staleTargets, 0, 10)) . (count($staleTargets) > 10 ? ', ...' : '');

		return self::result(
			'smokeping_stale_targets',
			'warn',
			sprintf('%d of %d SmokePing series are stale or never observed', count($staleTargets), $totalSeries),
			'Stale or never-observed series: ' . $sample
		);
	}

	/**
	 * @return array{id: string, category: string, status: string, summary: string, detail: string, repair: string, repairable: bool, assessment: string}
	 */
	private function freshnessBreachesResult(int $totalSeries, int $breachedCount): array {
		if ($totalSeries === 0) {
			return self::result('smokeping_freshness_breaches', 'unknown', 'No SmokePing series to evaluate freshness', 'No readable target series exist on poller ' . $this->settings->pollerId . '.');
		}

		if ($breachedCount === 0) {
			return self::result('smokeping_freshness_breaches', 'ok', 'No SmokePing freshness breaches', sprintf('All %d series are within step + %d s freshness allowance.', $totalSeries, $this->settings->freshnessAllowance));
		}

		return self::result(
			'smokeping_freshness_breaches',
			'warn',
			sprintf('%d SmokePing series exceed freshness allowance', $breachedCount),
			sprintf('%d of %d series on poller %d are delayed or stale beyond step + %d s.', $breachedCount, $totalSeries, $this->settings->pollerId, $this->settings->freshnessAllowance)
		);
	}

	/**
	 * @param list<string> $expectedFiles
	 * @return array{id: string, category: string, status: string, summary: string, detail: string, repair: string, repairable: bool, assessment: string}
	 */
	private function orphanRrdsResult(array $expectedFiles): array {
		if (!$this->readerHost) {
			return self::result('smokeping_orphan_rrds', 'unknown', 'Orphan RRD check runs only on the SmokePing reader poller', sprintf('This host is not poller %d.', $this->settings->pollerId));
		}

		$containment = MountCheck::withinRra($this->settings->mountPath, $this->rraPath);

		if ($containment !== null || !is_dir($this->settings->mountPath)) {
			return self::result('smokeping_orphan_rrds', 'fail', 'SmokePing mount path is unavailable for orphan scan', $containment ?? ('Mount path does not exist: ' . $this->settings->mountPath));
		}

		$scan    = $this->orphanScanner->scan($this->settings->mountPath, $expectedFiles);
		$orphans = $scan['orphans'];

		if ($orphans === []) {
			return self::result('smokeping_orphan_rrds', 'ok', 'No orphan SmokePing RRD files detected', sprintf('Scanned %d RRD files under %s.', $scan['scanned'], $this->settings->mountPath));
		}

		$names = array_map(static fn (array $o): string => $o['relative'], array_slice($orphans, 0, 10));
		$extra = count($orphans) > 10 ? ', ...' : '';
		$trunc = $scan['truncated'] ? ' (scan budget reached)' : '';

		return self::result(
			'smokeping_orphan_rrds',
			'warn',
			sprintf('%d orphan SmokePing RRD file(s) found%s', count($orphans), $trunc),
			'Unowned RRD files: ' . implode(', ', $names) . $extra
		);
	}

	/**
	 * @param list<string> $expectedFiles
	 * @return array{id: string, category: string, status: string, summary: string, detail: string, repair: string, repairable: bool, assessment: string}
	 */
	private function configPathResult(array $expectedFiles): array {
		$configFinding = HealthCheck::configFile($this->settings->configFile, $this->readerHost, $this->settings->pollerId);
		$mountFindings = $this->mountCheck->check($this->settings->mountPath, $this->rraPath, $expectedFiles);
		$all           = array_merge([$configFinding], $mountFindings);

		$worst   = HealthLevel::Ok;
		$details = [];

		foreach ($all as $finding) {
			$details[] = $finding->check . ': ' . $finding->message;
			$worst     = self::worse($worst, $finding->level);
		}

		$status = match ($worst) {
			HealthLevel::Error   => 'fail',
			HealthLevel::Warning => 'warn',
			HealthLevel::Unknown => 'unknown',
			HealthLevel::Ok      => 'ok',
		};

		return self::result(
			'smokeping_config_path',
			$status,
			'SmokePing configuration and mount path status: ' . $status,
			implode(' | ', $details)
		);
	}

	/**
	 * Build a redacted plain-array diagnostic snapshot for doctor_register_debug_info.
	 *
	 * Omits raw filesystem paths and any sensitive runtime values while exposing
	 * operational metadata useful for support bundles.
	 *
	 * @param Health $health Reader and inventory health record for the configured poller.
	 * @param string $pluginVersion Installed plugin version string from INFO.
	 * @param int $schemaVersion Applied database schema version.
	 * @return array{label: string, description: string, fields: array<string, array{label: string, value: string, private: bool}>}
	 */
	public function debugInfo(Health $health, string $pluginVersion, int $schemaVersion): array {
		$configReadable = $this->readerHost ? (is_readable($this->settings->configFile) ? 'yes' : 'no') : 'remote-poller';
		$mountReadable  = $this->readerHost ? (is_dir($this->settings->mountPath) && is_readable($this->settings->mountPath) ? 'yes' : 'no') : 'remote-poller';

		return [
			'label'       => 'SmokePing',
			'description' => 'Redacted SmokePing plugin configuration, schema, and reader telemetry snapshot.',
			'fields'      => [
				'plugin_version'        => ['label' => 'Plugin Version', 'value' => $pluginVersion, 'private' => false],
				'schema_version'        => ['label' => 'Schema Version', 'value' => (string) $schemaVersion, 'private' => false],
				'poller_id'             => ['label' => 'Reader Poller ID', 'value' => (string) $this->settings->pollerId, 'private' => false],
				'reader_host'           => ['label' => 'Running on Reader Poller', 'value' => $this->readerHost ? 'yes' : 'no', 'private' => false],
				'reader_budget_s'       => ['label' => 'Reader Budget (s)', 'value' => (string) $this->settings->readerBudget, 'private' => false],
				'inventory_interval_s'  => ['label' => 'Inventory Interval (s)', 'value' => (string) $this->settings->inventoryInterval, 'private' => false],
				'freshness_allowance_s' => ['label' => 'Freshness Allowance (s)', 'value' => (string) $this->settings->freshnessAllowance, 'private' => false],
				'keep_tables_uninstall' => ['label' => 'Keep Tables on Uninstall', 'value' => $this->settings->keepTablesOnUninstall ? 'yes' : 'no', 'private' => false],
				'config_file_readable'  => ['label' => 'Config File Readable', 'value' => $configReadable, 'private' => false],
				'mount_path_readable'   => ['label' => 'Mount Path Readable', 'value' => $mountReadable, 'private' => false],
				'inventory_status'      => ['label' => 'Inventory Status', 'value' => $health->inventoryStatus->value, 'private' => false],
				'inventory_targets'     => ['label' => 'Inventory Targets', 'value' => (string) $health->inventoryTargets, 'private' => false],
				'series_total'          => ['label' => 'Series Total', 'value' => (string) $health->seriesTotal, 'private' => false],
				'series_read'           => ['label' => 'Series Read', 'value' => (string) $health->seriesRead, 'private' => false],
				'budget_overruns'       => ['label' => 'Budget Overruns', 'value' => (string) $health->budgetOverruns, 'private' => false],
				'smokeping_version'     => ['label' => 'Detected SmokePing Version', 'value' => $health->smokepingVersion !== '' ? $health->smokepingVersion : 'unknown', 'private' => false],
				'config_path'           => ['label' => 'Config Path', 'value' => '[redacted]', 'private' => true],
				'mount_path'            => ['label' => 'Mount Path', 'value' => '[redacted]', 'private' => true],
			],
		];
	}

	/**
	 * Attach the redacted SmokePing debug info section to an array or registrar object.
	 *
	 * @param mixed $sections Array accumulator or registrar object with register(string, array).
	 * @param Health $health Reader and inventory health record for the configured poller.
	 * @param string $pluginVersion Installed plugin version string from INFO.
	 * @param int $schemaVersion Applied database schema version.
	 * @return mixed Updated sections accumulator or registrar.
	 */
	public function registerDebugInfo($sections, Health $health, string $pluginVersion, int $schemaVersion) {
		$entry = $this->debugInfo($health, $pluginVersion, $schemaVersion);

		if (is_array($sections)) {
			$sections[self::CATEGORY] = $entry;

			return $sections;
		}

		if (is_object($sections) && method_exists($sections, 'register')) {
			$sections->register(self::CATEGORY, $entry);
		}

		return $sections;
	}

	/**
	 * Return the more severe of two health levels.
	 *
	 * @param HealthLevel $a First health level.
	 * @param HealthLevel $b Second health level.
	 * @return HealthLevel The higher-severity health level.
	 */
	private static function worse(HealthLevel $a, HealthLevel $b): HealthLevel {
		$rank = static fn (HealthLevel $level): int => match ($level) {
			HealthLevel::Error   => 3,
			HealthLevel::Warning => 2,
			HealthLevel::Unknown => 1,
			HealthLevel::Ok      => 0,
		};

		return $rank($b) > $rank($a) ? $b : $a;
	}

	/**
	 * Format a single Doctor check result entry.
	 *
	 * @param string $id Stable check identifier.
	 * @param string $status Doctor status string (ok, warn, fail, unknown).
	 * @param string $summary One-line summary message.
	 * @param string $detail Detailed diagnostic text.
	 * @return array{id: string, category: string, status: string, summary: string, detail: string, repair: string, repairable: bool, assessment: string}
	 */
	private static function result(string $id, string $status, string $summary, string $detail): array {
		return [
			'id'         => $id,
			'category'   => self::CATEGORY,
			'status'     => $status,
			'summary'    => $summary,
			'detail'     => $detail,
			'repair'     => '',
			'repairable' => false,
			'assessment' => 'assessed',
		];
	}
}
