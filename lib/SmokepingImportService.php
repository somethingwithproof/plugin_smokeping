<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 |                                                                         |
 | This program is distributed in the hope that it will be useful,         |
 | but WITHOUT ANY WARRANTY; without even the implied warranty of          |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the           |
 | GNU General Public License for more details.                            |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
 | This code is designed, written, and maintained by the Cacti Group. See  |
 | about.php and/or the AUTHORS file for specific developer information.   |
 +-------------------------------------------------------------------------+
 | http://www.cacti.net/                                                   |
 +-------------------------------------------------------------------------+
*/

namespace Cacti\Smokeping;

/**
 * Import an existing SmokePing configuration into Cacti: read it, plan the
 * devices, graphs, and graph tree it maps to, and apply a confirmed plan
 * through Cacti's own APIs. The admin page and the command line both go
 * through this class.
 *
 * The configuration is the server's file, read with its includes, or an
 * uploaded text, read on its own. Nothing is ever written to SmokePing's
 * files or to the data directory mount.
 */
final class SmokepingImportService {
	/**
	 * @param \Closure|null $unrestricted whether the acting user may see every
	 *                                    device; null for the command line
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly SmokepingImportRepository $repository,
		private readonly TargetRepository $targets,
		private readonly TargetActions $actions,
		private readonly SmokeProvisioner $provisioner,
		private readonly CactiObjectRepository $cacti,
		private readonly string $coreLibrary,
		private readonly bool $rrdProxy,
		private readonly ?\Closure $unrestricted = null,
	) {
	}

	/**
	 * Read the configuration: the configured file when $upload is null.
	 *
	 * @return array{0: Inventory, 1: string} the inventory and a digest of what was read
	 */
	public function read(?string $upload, string $uploadName = 'uploaded file'): array {
		$this->requireUnrestricted();

		if ($upload === null) {
			$inventory = (new SmokepingConfigReader($this->settings->configFile))->read($this->settings->pollerId);
		} else {
			$inventory = SmokepingConfigReader::fromText($upload, $uploadName, $this->settings->pollerId);
		}

		$paths = array_map(fn (Target $t) => [$t->path->value, $t->host, $t->probe, $t->menu, $t->title, $t->slaves, $t->noMasterPoll, $t->step, $t->pings], $inventory->targets);

		return [$inventory, hash('sha256', ($upload === null ? 'server' : 'upload') . (string) json_encode($paths))];
	}

	public function defaultTemplateId(): int {
		return $this->cacti->deviceTemplateId(TemplateInstaller::DEVICE_TEMPLATE);
	}

	public function readerPollerId(): int {
		return $this->settings->pollerId;
	}

	public function plan(Inventory $inventory, string $digest, SmokepingImportOptions $options, bool $upload): SmokepingImportPlan {
		$this->requireUnrestricted();

		$state = $this->repository->state(
			$this->settings->pollerId,
			$this->rrdProxy,
			array_values(array_map(fn (Target $t) => $t->host, $inventory->targets)),
			$this->cacti->graphTemplateId(TemplateInstaller::SUMMARY_TEMPLATE) > 0
		);

		$plan = SmokepingImportPlanner::plan($inventory, $options, $state, $digest);

		if (!$upload) {
			return $plan;
		}

		$known = 0;

		foreach ($plan->targets as $target) {
			$known += $target->id !== null ? 1 : 0;
		}

		$note = sprintf('An uploaded file is read on its own, without @include. %d of its %d selected targets are not in the inventory of poller %d; Cacti graphs them from the RRD files of that poller\'s SmokePing, and its next inventory marks a target its SmokePing does not have as absent.', count($plan->targets) - $known, count($plan->targets), $this->settings->pollerId);

		return new SmokepingImportPlan($plan->digest, $plan->options, $plan->sections, $plan->targets, $plan->devices, $plan->graphs, $plan->summaryOnly, $plan->branches, $plan->leaves, $plan->errors, array_merge([$note], $plan->notes), $plan->load);
	}

	/**
	 * Apply the plan the administrator confirmed. The plan is computed again
	 * and must still have the confirmed id, so a change to the configuration
	 * or to Cacti since the check is refused rather than applied blind.
	 *
	 * @return array{0: list<string>, 1: list<string>} what was done, and the refusals
	 */
	public function apply(Inventory $inventory, string $digest, SmokepingImportOptions $options, bool $upload, string $confirm, int $userId, bool $mayEditTrees): array {
		$plan = $this->plan($inventory, $digest, $options, $upload);

		if (!hash_equals($plan->id(), $confirm)) {
			return [[], [__('The configuration or the installation changed since the check, so nothing was imported. Check again.', 'smokeping')]];
		}

		if ($plan->hasErrors()) {
			return [[], $plan->errors];
		}

		if ($options->mirrorsTree() && !$mayEditTrees) {
			return [[], [__('Mirroring into a graph tree needs permission to edit trees.', 'smokeping')]];
		}

		$selected = array_values(array_filter($inventory->targets, fn (Target $t) => $options->selects($t->path->value)));
		$stored   = (new InventoryImporter())->plan(new Inventory($inventory->smokepingVersion, $inventory->datadir, $selected, []), $this->targets->forPoller($this->settings->pollerId));

		// Only the selected targets are stored or refreshed; this is not an
		// inventory run, so nothing is marked absent and no re-link is proposed.
		if (!$this->targets->applyImport(new ImportPlan($stored->added, $stored->changed, [], [], []), $this->settings->pollerId, time())) {
			return [[], [__('The targets could not be stored; see the Cacti log. Nothing else was changed.', 'smokeping')]];
		}

		$byPath = [];

		foreach ($this->targets->forPoller($this->settings->pollerId) as $target) {
			$byPath[$target->path->value] = $target;
		}

		$done    = [];
		$hostIds = [];
		$pending = [];

		foreach ($plan->graphs as $graph) {
			if (!$graph['exists']) {
				$pending[$graph['path']] = true;
			}
		}

		foreach ($plan->devices as $device) {
			if ($device['action'] === 'ambiguous') {
				$done[] = __('%s: skipped, %s', implode(', ', $device['targets']), $device['reason'], 'smokeping');

				continue;
			}

			$hostId = $device['host_id'];

			if ($device['action'] === 'create') {
				$hostId = $this->provisioner->saveDevice($device['description'], $device['hostname'], $options->templateId, $options->pollerId, $options->siteId);

				if ($hostId === 0) {
					$done[] = __('%s: skipped, Cacti did not create the device', $device['hostname'], 'smokeping');

					continue;
				}

				$done[] = __('%s: created device %d', $device['description'], $hostId, 'smokeping');
			}

			$hostIds[$device['key']] = $hostId;

			foreach ($device['targets'] as $path) {
				$target = $byPath[$path] ?? null;

				if ($target === null) {
					$done[] = __('%s: skipped, the target was not stored', $path, 'smokeping');

					continue;
				}

				// A re-run reports what exists instead of provisioning it again.
				if ($device['action'] === 'mapped' && !isset($pending[$path])) {
					$done[] = __('%s: already in Cacti on device %d', $path, $hostId, 'smokeping');

					continue;
				}

				$done = array_merge($done, $this->actions->add($target, $hostId, $userId)->lines());
			}
		}

		if ($options->mirrorsTree()) {
			$done = array_merge($done, $this->mirror($plan, $options, $hostIds, $userId));
		}

		Log::info(sprintf('SmokePing configuration import by user %d: plan %s, %d devices, %d targets', $userId, $plan->id(), count($hostIds), count($plan->targets)));

		return [$done, []];
	}

	/**
	 * Mirror the hierarchy into the graph tree: one branch per section, and
	 * each device as a host leaf under the section of its targets. Existing
	 * branches and leaves are reused.
	 *
	 * @param array<string, int> $hostIds device key => device id
	 * @return list<string>
	 */
	private function mirror(SmokepingImportPlan $plan, SmokepingImportOptions $options, array $hostIds, int $userId): array {
		include_once($this->coreLibrary . '/api_tree.php');

		$lines  = [];
		$treeId = $options->treeId > 0 ? $options->treeId : $this->repository->treeByName($options->treeName);

		if ($treeId === 0) {
			$treeId = $this->repository->createTree($options->treeName, $userId);

			if ($treeId === 0) {
				return [__('The graph tree %s could not be created.', $options->treeName, 'smokeping')];
			}

			$lines[] = __('created graph tree %s', $options->treeName, 'smokeping');
		}

		$branchIds = ['' => 0];

		foreach ($plan->branches as $branch) {
			$slash  = strrpos($branch, '/');
			$parent = $branchIds[$slash === false ? '' : substr($branch, 0, $slash)] ?? 0;
			$title  = $slash === false ? $branch : substr($branch, $slash + 1);
			$id     = (int) api_tree_branch_exists($treeId, $parent, $title);

			if ($id === 0) {
				$id = (int) api_tree_item_save(0, $treeId, TREE_ITEM_TYPE_HEADER, $parent, $title, 0, 0, 0, HOST_GROUPING_GRAPH_TEMPLATE, TREE_ORDERING_INHERIT, false);

				$lines[] = $id > 0 ? __('tree branch %s', $branch, 'smokeping') : __('the tree branch %s could not be created', $branch, 'smokeping');
			}

			$branchIds[$branch] = $id;
		}

		foreach ($plan->leaves as $leaf) {
			$hostId = $hostIds[$leaf['device']] ?? 0;
			$parent = $branchIds[$leaf['branch']] ?? 0;

			if ($hostId === 0 || ($leaf['branch'] !== '' && $parent === 0)) {
				continue;
			}

			if ((int) api_tree_host_exists($treeId, $parent, $hostId) > 0) {
				continue;
			}

			$id      = (int) api_tree_item_save(0, $treeId, TREE_ITEM_TYPE_HOST, $parent, '', 0, $hostId, 0, HOST_GROUPING_GRAPH_TEMPLATE, TREE_ORDERING_INHERIT, false);
			$lines[] = $id > 0 ? __('device %d under tree branch %s', $hostId, $leaf['branch'] === '' ? '/' : $leaf['branch'], 'smokeping') : __('device %d could not be added to the tree', $hostId, 'smokeping');
		}

		return $lines;
	}

	private function requireUnrestricted(): void {
		if ($this->unrestricted !== null && ($this->unrestricted)() !== true) {
			throw new InventoryError('Your device permissions do not cover every device, so you cannot import a SmokePing configuration.');
		}
	}
}
