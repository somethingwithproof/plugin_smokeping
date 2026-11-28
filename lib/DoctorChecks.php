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
	 * Run or list all SmokePing diagnostic checks in Doctor's canonical descriptor shape.
	 *
	 * @param string $mode Execution mode ('run' or 'list').
	 * @return list<array{
	 *     id: string,
	 *     plugin: string,
	 *     category: string,
	 *     title: string,
	 *     status: string,
	 *     message: string,
	 *     details: string,
	 *     remediation: string
	 * }>
	 */
	public function run(string $mode = 'run'): array {
		if ($mode === 'list') {
			return [
				self::result('smokeping.stale_targets', 'info', 'SmokePing stale targets', 'Checks whether SmokePing target series have recent observations.', '', 'Verify the SmokePing reader poller is running and RRD files are updating.'),
				self::result('smokeping.freshness_breaches', 'info', 'SmokePing freshness breaches', 'Checks whether SmokePing target observations stay within the freshness allowance.', '', 'Inspect SmokePing polling intervals and freshness allowance settings.'),
				self::result('smokeping.orphan_rrds', 'info', 'SmokePing orphan RRD files', 'Checks for unowned RRD files under the configured SmokePing mount path.', '', 'Review unowned RRD files under the SmokePing mount path and clean up retired targets.'),
				self::result('smokeping.config_path', 'info', 'SmokePing configuration and mount path', 'Checks SmokePing configuration file readability and mount containment.', '', 'Verify SmokePing config file and RRA mount path settings and permissions.'),
			];
		}

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

		return [
			$this->staleTargetsResult($totalSeries, $staleTargets),
			$this->freshnessBreachesResult($totalSeries, $breachedCount),
			$this->orphanRrdsResult($expectedFiles),
			$this->configPathResult($expectedFiles),
		];
	}

	/**
	 * Evaluate SmokePing checks for the doctor_register_checks hook without echoing $context.
	 *
	 * @param mixed $context Doctor hook context array (expects optional 'mode' key).
	 * @return list<array{
	 *     id: string,
	 *     plugin: string,
	 *     category: string,
	 *     title: string,
	 *     status: string,
	 *     message: string,
	 *     details: string,
	 *     remediation: string
	 * }>
	 */
	public function register($context = []): array {
		$mode = is_array($context) && is_string($context['mode'] ?? null) ? $context['mode'] : 'run';

		return $this->run($mode);
	}

	/**
	 * Build the stale-targets check descriptor.
	 *
	 * @param int          $totalSeries  Total readable target series.
	 * @param list<string> $staleTargets List of stale or never-observed series identifiers.
	 * @return array{id: string, plugin: string, category: string, title: string, status: string, message: string, details: string, remediation: string}
	 */
	private function staleTargetsResult(int $totalSeries, array $staleTargets): array {
		$title = 'SmokePing stale targets';

		if ($totalSeries === 0) {
			return self::result('smokeping.stale_targets', 'info', $title, 'No SmokePing target series imported', 'No readable SmokePing target series exist on poller ' . $this->settings->pollerId . '.', '');
		}

		if ($staleTargets === []) {
			return self::result('smokeping.stale_targets', 'ok', $title, 'All SmokePing target series are active', sprintf('All %d series on poller %d have recent observations.', $totalSeries, $this->settings->pollerId), '');
		}

		$sample = implode(', ', array_slice($staleTargets, 0, 10)) . (count($staleTargets) > 10 ? ', ...' : '');

		return self::result(
			'smokeping.stale_targets',
			'warn',
			$title,
			sprintf('%d of %d SmokePing series are stale or never observed', count($staleTargets), $totalSeries),
			'Stale or never-observed series: ' . $sample,
			'Confirm SmokePing is updating RRD files for these targets and the reader poller has read access.'
		);
	}

	/**
	 * Build the freshness-breaches check descriptor.
	 *
	 * @param int $totalSeries   Total readable target series.
	 * @param int $breachedCount Number of series exceeding freshness allowance.
	 * @return array{id: string, plugin: string, category: string, title: string, status: string, message: string, details: string, remediation: string}
	 */
	private function freshnessBreachesResult(int $totalSeries, int $breachedCount): array {
		$title = 'SmokePing freshness breaches';

		if ($totalSeries === 0) {
			return self::result('smokeping.freshness_breaches', 'info', $title, 'No SmokePing series to evaluate freshness', 'No readable target series exist on poller ' . $this->settings->pollerId . '.', '');
		}

		if ($breachedCount === 0) {
			return self::result('smokeping.freshness_breaches', 'ok', $title, 'No SmokePing freshness breaches', sprintf('All %d series are within step + %d s freshness allowance.', $totalSeries, $this->settings->freshnessAllowance), '');
		}

		return self::result(
			'smokeping.freshness_breaches',
			'warn',
			$title,
			sprintf('%d SmokePing series exceed freshness allowance', $breachedCount),
			sprintf('%d of %d series on poller %d are delayed or stale beyond step + %d s.', $breachedCount, $totalSeries, $this->settings->pollerId, $this->settings->freshnessAllowance),
			'Verify SmokePing probe intervals and reader poller execution frequency.'
		);
	}

	/**
	 * Build the orphan-RRDs check descriptor.
	 *
	 * @param list<string> $expectedFiles Expected RRD file paths for configured targets.
	 * @return array{id: string, plugin: string, category: string, title: string, status: string, message: string, details: string, remediation: string}
	 */
	private function orphanRrdsResult(array $expectedFiles): array {
		$title = 'SmokePing orphan RRD files';

		if (!$this->readerHost) {
			return self::result('smokeping.orphan_rrds', 'info', $title, 'Orphan RRD check runs only on the SmokePing reader poller', sprintf('This host is not poller %d.', $this->settings->pollerId), '');
		}

		$containment = MountCheck::withinRra($this->settings->mountPath, $this->rraPath);

		if ($containment !== null || !is_dir($this->settings->mountPath)) {
			return self::result(
				'smokeping.orphan_rrds',
				'fail',
				$title,
				'SmokePing mount path is unavailable for orphan scan',
				$containment ?? ('Mount path does not exist: ' . $this->settings->mountPath),
				'Mount the SmokePing RRD directory inside the Cacti RRA path and verify read permissions.'
			);
		}

		$scan    = $this->orphanScanner->scan($this->settings->mountPath, $expectedFiles);
		$orphans = $scan['orphans'];

		if ($orphans === []) {
			return self::result('smokeping.orphan_rrds', 'ok', $title, 'No orphan SmokePing RRD files detected', sprintf('Scanned %d RRD files under %s.', $scan['scanned'], $this->settings->mountPath), '');
		}

		$names = array_map(static fn (array $o): string => $o['relative'], array_slice($orphans, 0, 10));
		$extra = count($orphans) > 10 ? ', ...' : '';
		$trunc = $scan['truncated'] ? ' (scan budget reached)' : '';

		return self::result(
			'smokeping.orphan_rrds',
			'warn',
			$title,
			sprintf('%d orphan SmokePing RRD file(s) found%s', count($orphans), $trunc),
			'Unowned RRD files: ' . implode(', ', $names) . $extra,
			'Archive or remove unowned RRD files that no longer belong to active SmokePing targets.'
		);
	}

	/**
	 * Build the config-and-mount-path check descriptor.
	 *
	 * @param list<string> $expectedFiles Expected RRD file paths for configured targets.
	 * @return array{id: string, plugin: string, category: string, title: string, status: string, message: string, details: string, remediation: string}
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
			HealthLevel::Unknown => 'info',
			HealthLevel::Ok      => 'ok',
		};

		return self::result(
			'smokeping.config_path',
			$status,
			'SmokePing configuration and mount path',
			'SmokePing configuration and mount path status: ' . $status,
			implode(' | ', $details),
			$status === 'ok' || $status === 'info' ? '' : 'Check SmokePing configuration file path, mount containment, and filesystem permissions.'
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
	 * @return array{id: string, plugin: string, label: string, description: string, private: bool, fields: array<string, array{label: string, value: string, private: bool}>}
	 */
	public function debugInfo(Health $health, string $pluginVersion, int $schemaVersion): array {
		$configReadable = $this->readerHost ? (is_readable($this->settings->configFile) ? 'yes' : 'no') : 'remote-poller';
		$mountReadable  = $this->readerHost ? (is_dir($this->settings->mountPath) && is_readable($this->settings->mountPath) ? 'yes' : 'no') : 'remote-poller';

		return [
			'id'          => 'smokeping.overview',
			'plugin'      => self::CATEGORY,
			'label'       => 'SmokePing',
			'description' => 'Redacted SmokePing plugin configuration, schema, and reader telemetry snapshot.',
			'private'     => false,
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
	 * Return the redacted SmokePing debug info section list without echoing $context.
	 *
	 * @param mixed $context Doctor debug-info hook context.
	 * @param Health $health Reader and inventory health record for the configured poller.
	 * @param string $pluginVersion Installed plugin version string from INFO.
	 * @param int $schemaVersion Applied database schema version.
	 * @return list<array{id: string, plugin: string, label: string, description: string, private: bool, fields: array<string, array{label: string, value: string, private: bool}>}>
	 */
	public function registerDebugInfo($context, Health $health, string $pluginVersion, int $schemaVersion): array {
		unset($context);

		return [$this->debugInfo($health, $pluginVersion, $schemaVersion)];
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
	 * Normalize arbitrary status tokens to Doctor's strict ok|warn|fail|info enum.
	 *
	 * @param string $status Raw status string.
	 * @return string Normalized Doctor status ('ok', 'warn', 'fail', or 'info').
	 */
	private static function normalizeStatus(string $status): string {
		return match (strtolower(trim($status))) {
			'ok', 'pass'       => 'ok',
			'warn', 'warning'  => 'warn',
			'fail', 'critical', 'error' => 'fail',
			default            => 'info',
		};
	}

	/**
	 * Format a single canonical Doctor check descriptor entry.
	 *
	 * @param string $id          Stable dot-prefixed check identifier.
	 * @param string $status      Status string normalized to ok, warn, fail, or info.
	 * @param string $title       Human-readable check title.
	 * @param string $message     One-line status message.
	 * @param string $details     Detailed diagnostic text.
	 * @param string $remediation Actionable remediation guidance.
	 * @return array{id: string, plugin: string, category: string, title: string, status: string, message: string, details: string, remediation: string}
	 */
	private static function result(string $id, string $status, string $title, string $message, string $details, string $remediation): array {
		return [
			'id'          => $id,
			'plugin'      => self::CATEGORY,
			'category'    => self::CATEGORY,
			'title'       => $title,
			'status'      => self::normalizeStatus($status),
			'message'     => $message,
			'details'     => $details,
			'remediation' => $remediation,
		];
	}
}
