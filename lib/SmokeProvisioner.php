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
 * Creates Cacti devices, smoke data sources, and smoke graphs for targets.
 *
 * Everything goes through Cacti's own functions (api_device_save(),
 * create_complete_graph_from_template()); the only direct writes are the path
 * and step of the new data source, because Cacti has no API for a path that
 * the data input method does not own. Cacti never polls or writes these data
 * sources: the template's input method is None.
 */
final class SmokeProvisioner {
	public function __construct(
		private readonly Settings $settings,
		private readonly string $rraPath,
		private readonly string $coreLibrary,
		private readonly bool $rrdProxy,
		private readonly ObjectRepository $objects,
		private readonly CactiObjectRepository $cacti,
		private readonly Clock $clock,
	) {
	}

	/**
	 * Create a device from the SmokePing Target template. The plugin sets
	 * availability None itself: Cacti 1.2.31 keeps it on the device, not the
	 * template.
	 *
	 * @return int the new device id, or 0 when Cacti refused it
	 */
	public function createDevice(Target $target): int {
		include_once($this->coreLibrary . '/api_device.php');

		$templateId = $this->cacti->deviceTemplateId(TemplateInstaller::DEVICE_TEMPLATE);

		if ($templateId === 0 || $target->id === null) {
			return 0;
		}

		$hostId = (int) api_device_save(0, $templateId, $target->displayName(), $target->host, '', 0, '', '', 161, 500, '', AVAIL_NONE, 0, 23, 400, 1, '', 'MD5', '', 'DES', '', '', 5, 1, $target->pollerId);

		if ($hostId > 0) {
			$this->objects->mapTarget($target->id->value, $hostId, 0, $this->clock->now());
		}

		return $hostId;
	}

	/**
	 * Why this target location cannot get a smoke data source, or null when it
	 * can. Checked before a device is created, so a refusal leaves nothing
	 * behind.
	 */
	public function refusal(Target $target, string $location = ''): ?string {
		if ($target->id === null) {
			return __('The target has not been saved yet.', 'smokeping');
		}

		if (!in_array($target->pings, TemplateInstaller::PINGS, true)) {
			return __('The target sends %d pings per round. Smoke graphs exist only for 10, 20, and 30; it has summary data only.', $target->pings, 'smokeping');
		}

		if ($this->rrdProxy) {
			return __('Cacti keeps RRD files on an RRDproxy, where SmokePing\'s files do not exist. Only summary data is available.', 'smokeping');
		}

		$relative = $this->relativePath($target->path, $location);

		return $relative instanceof ProvisionResult ? $relative->reason : null;
	}

	/**
	 * Create the smoke data source and graph for one target location on a
	 * device. Repeating the call for a target that has one returns it.
	 */
	public function provision(Target $target, int $hostId, string $location = ''): ProvisionResult {
		$reason = $this->refusal($target, $location);

		if ($reason !== null) {
			return ProvisionResult::skipped($reason);
		}

		if ($target->id === null) {
			return ProvisionResult::skipped(__('The target has not been saved yet.', 'smokeping'));
		}

		$existing = $this->objects->active($target->id->value, $location);

		if ($existing !== null) {
			return ProvisionResult::created($existing['local_data_id'], $existing['local_graph_id']);
		}

		return $this->create($target, $hostId, $location, $target->displayName());
	}

	/**
	 * Why this target location cannot get a summary data source on this
	 * device, or null when it can. The script reads the cache of the poller
	 * next to SmokePing, so the device must be polled by that poller.
	 */
	public function summaryRefusal(Target $target, int $hostId): ?string {
		if ($target->id === null) {
			return __('The target has not been saved yet.', 'smokeping');
		}

		$poller = $this->cacti->devicePoller($hostId);

		if ($poller !== $this->settings->pollerId) {
			return __('The device is polled by poller %d, but the reader that fills the summary values runs on poller %d. Assign the device to poller %d.', $poller, $this->settings->pollerId, $this->settings->pollerId, 'smokeping');
		}

		if ($this->cacti->graphTemplateId(TemplateInstaller::SUMMARY_TEMPLATE) === 0) {
			return __('The summary graph template is not installed.', 'smokeping');
		}

		return null;
	}

	/**
	 * Create the polled summary data source and graph for one target location.
	 * Repeating the call returns the existing one.
	 */
	public function provisionSummary(Target $target, int $hostId, string $location = ''): ProvisionResult {
		$reason = $this->summaryRefusal($target, $hostId);

		if ($reason !== null || $target->id === null) {
			return ProvisionResult::skipped($reason ?? '');
		}

		$existing = $this->objects->summary($target->id->value, $location);

		if ($existing !== null) {
			return ProvisionResult::created($existing['local_data_id'], $existing['local_graph_id']);
		}

		include_once($this->coreLibrary . '/template.php');
		include_once($this->coreLibrary . '/utility.php');

		$templateId     = $this->cacti->graphTemplateId(TemplateInstaller::SUMMARY_TEMPLATE);
		$dataTemplateId = $this->cacti->dataTemplateOfGraphTemplate($templateId);
		$fieldId        = $this->cacti->dataInputFieldId(TemplateInstaller::SUMMARY_INPUT, 'series');

		if ($dataTemplateId === 0 || $fieldId === 0) {
			return ProvisionResult::skipped(__('The summary data template is not installed.', 'smokeping'));
		}

		$series    = SummaryScript::series($target->id->value, $location);
		$suggested = [$templateId => ['custom_data' => [$dataTemplateId => [$fieldId => $series]]]];
		$none      = [];
		$created   = create_complete_graph_from_template($templateId, $hostId, $none, $suggested);

		$localGraphId = is_array($created) && is_numeric($created['local_graph_id'] ?? null) ? (int) $created['local_graph_id'] : 0;
		$sources      = $localGraphId > 0 ? $this->cacti->dataSourcesOfGraph($localGraphId) : [];

		if (count($sources) !== 1) {
			Log::error(sprintf('Creating the summary graph for %s produced %d data sources', $target->path->value, count($sources)));

			return ProvisionResult::skipped(__('Cacti did not create the summary graph.', 'smokeping'));
		}

		$this->objects->add($target->id->value, $location, $hostId, $sources[0], $localGraphId, '', ObjectRepository::SUMMARY, $this->clock->now());

		// Fills the poller cache from the series just stored, so the next poll runs the script.
		push_out_host($hostId, $sources[0]);

		if ($location !== '') {
			$this->retitle($localGraphId, $this->title($target->displayName(), 'SmokePing Summary', $location));
		}

		return ProvisionResult::created($sources[0], $localGraphId);
	}

	/**
	 * Graph title: the location is named for slave locations, so two graphs of
	 * one target are told apart.
	 */
	public function title(string $name, string $kind, string $location): string {
		return $location === '' ? $name . ' - ' . $kind : sprintf('%s - %s (%s)', $name, $kind, $location);
	}

	/**
	 * Re-link: the target's smoke data source moves to the new target's file,
	 * and an archived data source and graph keep the old file's history.
	 */
	public function relink(Target $old, Target $new, string $location = ''): ProvisionResult {
		if ($old->id === null || $new->id === null) {
			return ProvisionResult::skipped(__('Both targets must be saved first.', 'smokeping'));
		}

		$live = $this->objects->active($old->id->value, $location);

		if ($live === null) {
			return ProvisionResult::skipped(__('The old target has no smoke data source to move.', 'smokeping'));
		}

		$newRelative = $this->relativePath($new->path, $location);

		if ($newRelative instanceof ProvisionResult) {
			return $newRelative;
		}

		$hostId      = $this->objects->hostOf($live['local_data_id']);

		// The archive keeps the old file, so it must exist before the live
		// data source leaves it.
		$archive = $this->create($old, $hostId, $location, $old->displayName() . ' (archived)', ObjectRepository::ARCHIVED);

		if (!$archive->created) {
			return $archive;
		}

		$this->cacti->pointDataSource($live['local_data_id'], '<path_rra>/' . $newRelative, $new->step);
		$this->objects->repoint($live['local_data_id'], $new->id->value, $newRelative);

		return ProvisionResult::created($live['local_data_id'], $live['local_graph_id']);
	}

	/**
	 * The file's path relative to rra_path, or the reason it cannot be used.
	 * The file must exist, lie under the mount, and be reached without a
	 * symlink: Cacti 1.2.x rejects anything else.
	 */
	private function relativePath(TargetPath $path, string $location): string|ProvisionResult {
		$file = $path->rrdFile($this->settings->mountPath, $location);

		$problem = MountCheck::withinRra($file, $this->rraPath);

		if ($problem !== null) {
			return ProvisionResult::skipped($problem);
		}

		$real = realpath($file);
		$root = realpath($this->settings->mountPath);

		if ($real === false || $root === false || !is_file($real)) {
			return ProvisionResult::skipped(__('The RRD file %s is not visible through the SmokePing mount.', $file, 'smokeping'));
		}

		if (!str_starts_with($real, $root . '/')) {
			return ProvisionResult::skipped(__('The RRD file %s resolves outside the SmokePing mount.', $file, 'smokeping'));
		}

		return $this->relativePathOf($path, $location);
	}

	private function relativePathOf(TargetPath $path, string $location): string {
		$file = $path->rrdFile($this->settings->mountPath, $location);

		return substr($file, strlen(rtrim($this->rraPath, '/')) + 1);
	}

	private function create(Target $target, int $hostId, string $location, string $title, string $state = ObjectRepository::ACTIVE): ProvisionResult {
		include_once($this->coreLibrary . '/template.php');
		include_once($this->coreLibrary . '/functions.php');

		if ($target->id === null) {
			return ProvisionResult::skipped(__('The target has not been saved yet.', 'smokeping'));
		}

		// Every path that creates a smoke data source passes the same checks,
		// including the archive of an old file: it must exist, lie under the
		// mount, and be reached without a symlink.
		$relative = $this->relativePath($target->path, $location);

		if ($relative instanceof ProvisionResult) {
			return $relative;
		}

		$templateId = $this->cacti->graphTemplateId(TemplateInstaller::graphTemplateName($target->pings));

		if ($templateId === 0) {
			return ProvisionResult::skipped(__('The smoke graph template is not installed.', 'smokeping'));
		}

		// Cacti reuses a device's existing data source for a data template unless
		// the suggested values differ from it. The path does, so a second graph
		// for the same device and another file gets a data source of its own,
		// and the path and step are set by Cacti's own suggested-values step.
		$dataTemplateId = $this->cacti->dataTemplateOfGraphTemplate($templateId);
		$suggested      = [$templateId => ['data_template' => [$dataTemplateId => ['data_source_path' => '<path_rra>/' . $relative, 'rrd_step' => $target->step]]]];
		$none           = [];
		$created      = create_complete_graph_from_template($templateId, $hostId, $none, $suggested);
		$localGraphId = is_array($created) && is_numeric($created['local_graph_id'] ?? null) ? (int) $created['local_graph_id'] : 0;
		$sources      = $localGraphId > 0 ? $this->cacti->dataSourcesOfGraph($localGraphId) : [];

		if (count($sources) !== 1) {
			Log::error(sprintf('Creating the smoke graph for %s produced %d data sources', $target->path->value, count($sources)));

			return ProvisionResult::skipped(__('Cacti did not create the smoke graph.', 'smokeping'));
		}

		$localDataId = $sources[0];

		$this->cacti->pointDataSource($localDataId, '<path_rra>/' . $relative, $target->step);

		// Cacti 1.2.32 and later replace a path they reject with a generated
		// one. Ask Cacti which path it will read before reporting success.
		$resolved = get_data_source_path($localDataId, true);

		if ($resolved !== rtrim($this->rraPath, '/') . '/' . $relative) {
			Log::error(sprintf('Cacti resolved the smoke data source for %s to %s instead of the SmokePing file', $target->path->value, (string) $resolved));
			$this->objects->add($target->id->value, $location, $hostId, $localDataId, $localGraphId, $relative, $state, $this->clock->now());
			$this->remove($localDataId, $localGraphId);

			return ProvisionResult::skipped(__('Cacti does not accept the SmokePing path for a data source.', 'smokeping'));
		}

		$this->objects->add($target->id->value, $location, $hostId, $localDataId, $localGraphId, $relative, $state, $this->clock->now());

		if ($title !== $target->displayName()) {
			$this->retitle($localGraphId, $title);
		} elseif ($location !== '') {
			$this->retitle($localGraphId, $this->title($title, 'SmokePing', $location));
		}

		return ProvisionResult::created($localDataId, $localGraphId);
	}

	private function retitle(int $localGraphId, string $title): void {
		include_once($this->coreLibrary . '/variables.php');

		$this->cacti->setGraphTitle($localGraphId, $title);

		// Graph lists read the cached title.
		update_graph_title_cache($localGraphId);
	}

	private function remove(int $localDataId, int $localGraphId): void {
		include_once($this->coreLibrary . '/api_graph.php');
		include_once($this->coreLibrary . '/api_data_source.php');

		// The removal hook disarms the purge because the object row exists.
		api_graph_remove($localGraphId);
		api_data_source_remove($localDataId);
	}
}
