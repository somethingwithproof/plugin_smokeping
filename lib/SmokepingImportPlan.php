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
 * The preview of an import of a SmokePing configuration: what would be
 * created, mapped, or left alone, and why. The administrator confirms it by
 * its id; the apply recomputes the plan and refuses when the id differs.
 */
final readonly class SmokepingImportPlan {
	/**
	 * @param array<string, int> $sections section path => number of targets under it
	 * @param list<Target> $targets the selected targets
	 * @param list<array{key: string, hostname: string, description: string, action: string, host_id: int, targets: list<string>, reason: string}> $devices
	 * @param list<array{path: string, location: string, kind: string, exists: bool}> $graphs
	 * @param array<string, string> $summaryOnly target path => why it gets no smoke graph
	 * @param list<string> $branches graph tree branches, parents first
	 * @param list<array{branch: string, device: string}> $leaves device leaves by branch ('' is the tree's root)
	 * @param list<string> $errors problems that block the import
	 * @param list<string> $notes
	 */
	public function __construct(
		public string $digest,
		public SmokepingImportOptions $options,
		public array $sections,
		public array $targets,
		public array $devices,
		public array $graphs,
		public array $summaryOnly,
		public array $branches,
		public array $leaves,
		public array $errors,
		public array $notes,
		public LoadEstimate $load,
	) {
	}

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id(): string {
		$data = [
			$this->digest,
			$this->options->toArray(),
			array_map(fn (Target $t) => $t->path->value, $this->targets),
			$this->devices,
			$this->graphs,
			$this->summaryOnly,
			$this->branches,
			$this->leaves,
			$this->errors,
		];

		return substr(hash('sha256', (string) json_encode($data)), 0, 16);
	}

	/**
	 * Has errors.
	 *
	 * @return bool
	 */
	public function hasErrors(): bool {
		return $this->errors !== [];
	}

	/**
	 * Nothing would be created or mapped. A tree mirror is checked at apply
	 * time, which reports the branches and leaves that already exist.
	 */
	public function isEmpty(): bool {
		foreach ($this->devices as $device) {
			if (in_array($device['action'], ['create', 'map'], true)) {
				return false;
			}
		}

		foreach ($this->graphs as $graph) {
			if (!$graph['exists']) {
				return false;
			}
		}

		return !$this->options->mirrorsTree();
	}

	/**
	 * @return array{create: int, map: int, mapped: int, ambiguous: int, graphs: int, existing: int}
	 */
	public function counts(): array {
		$counts = ['create' => 0, 'map' => 0, 'mapped' => 0, 'ambiguous' => 0, 'graphs' => 0, 'existing' => 0];

		foreach ($this->devices as $device) {
			match ($device['action']) {
				'create' => $counts['create']++,
				'map'    => $counts['map']++,
				'mapped' => $counts['mapped']++,
				default  => $counts['ambiguous']++,
			};
		}

		foreach ($this->graphs as $graph) {
			if ($graph['exists']) {
				$counts['existing']++;
			} else {
				$counts['graphs']++;
			}
		}

		return $counts;
	}

	/**
	 * The plan as text, for the command line.
	 *
	 * @return list<string>
	 */
	public function lines(): array {
		$lines = [];

		foreach ($this->errors as $error) {
			$lines[] = 'error: ' . $error;
		}

		foreach ($this->notes as $note) {
			$lines[] = 'note: ' . $note;
		}

		foreach ($this->sections as $section => $count) {
			$lines[] = sprintf('section %s: %d targets', $section, $count);
		}

		foreach ($this->devices as $device) {
			$lines[] = match ($device['action']) {
				'create'    => sprintf('create device "%s" (%s) for %s', $device['description'], $device['hostname'], implode(', ', $device['targets'])),
				'map'       => sprintf('map %s to device %d (%s)', implode(', ', $device['targets']), $device['host_id'], $device['hostname']),
				'mapped'    => sprintf('already mapped: %s on device %d', implode(', ', $device['targets']), $device['host_id']),
				default     => sprintf('skip %s: %s', implode(', ', $device['targets']), $device['reason']),
			};
		}

		foreach ($this->graphs as $graph) {
			$lines[] = sprintf('%s %s graph for %s%s', $graph['exists'] ? 'existing' : 'create', $graph['kind'], $graph['path'], $graph['location'] !== '' ? ' at ' . $graph['location'] : '');
		}

		foreach ($this->summaryOnly as $path => $reason) {
			$lines[] = sprintf('summary only: %s: %s', $path, $reason);
		}

		if ($this->options->mirrorsTree()) {
			$tree = $this->options->treeId > 0 ? sprintf('tree %d', $this->options->treeId) : sprintf('tree "%s"', $this->options->treeName);

			foreach ($this->branches as $branch) {
				$lines[] = sprintf('%s: branch %s', $tree, $branch);
			}

			foreach ($this->leaves as $leaf) {
				$lines[] = sprintf('%s: device %s under %s', $tree, $leaf['device'], $leaf['branch'] === '' ? 'the root' : $leaf['branch']);
			}
		}

		foreach ($this->load->lines() as $line) {
			$lines[] = 'load: ' . $line;
		}

		return $lines;
	}
}
