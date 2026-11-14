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
 * What applying a configuration file would change, decided before anything is
 * written. A plan with errors is never applied. The id covers every change,
 * so a confirmation names exactly the plan the administrator looked at, and a
 * file or database that changed in between produces a different id.
 */
final readonly class ConfigPlan {
	/**
	 * @param list<array{name: string, from: string|int|bool|null, to: string|int|bool}> $settings
	 * @param list<Target> $targetAdds
	 * @param list<array{target: Target, fields: array<string, array{from: string|int|bool, to: string|int|bool}>}> $targetChanges
	 * @param list<array{poller_id: int, path: string, host_id: int, hostname: string, description: string}> $mappingAdds
	 * @param list<string> $errors
	 */
	public function __construct(
		public array $settings,
		public array $targetAdds,
		public array $targetChanges,
		public array $mappingAdds,
		public int $unchanged,
		public array $errors,
	) {
	}

	public function hasErrors(): bool {
		return $this->errors !== [];
	}

	public function isEmpty(): bool {
		return $this->settings === [] && $this->targetAdds === [] && $this->targetChanges === [] && $this->mappingAdds === [];
	}

	public function id(): string {
		$parts = [
			$this->settings,
			array_map(fn (Target $t) => self::fields($t), $this->targetAdds),
			array_map(fn (array $c) => [$c['target']->pollerId, $c['target']->path->value, $c['fields']], $this->targetChanges),
			$this->mappingAdds,
			$this->errors,
		];

		return substr(hash('sha256', 'smokeping-config-plan-1' . json_encode($parts)), 0, 16);
	}

	/**
	 * Human-readable diff, one change per line.
	 *
	 * @return list<string>
	 */
	public function lines(): array {
		$lines = [];

		foreach ($this->errors as $error) {
			$lines[] = 'ERROR  ' . $error;
		}

		foreach ($this->settings as $change) {
			$lines[] = sprintf('change setting %s: %s -> %s', $change['name'], self::show($change['from']), self::show($change['to']));
		}

		foreach ($this->targetAdds as $target) {
			$lines[] = sprintf('add    target %s on poller %d (host %s, probe %s)', $target->path->value, $target->pollerId, self::show($target->host), self::show($target->probe));
		}

		foreach ($this->targetChanges as $change) {
			$parts = [];

			foreach ($change['fields'] as $field => $values) {
				$parts[] = sprintf('%s: %s -> %s', $field, self::show($values['from']), self::show($values['to']));
			}

			$lines[] = sprintf('change target %s on poller %d: %s', $change['target']->path->value, $change['target']->pollerId, implode(', ', $parts));
		}

		foreach ($this->mappingAdds as $mapping) {
			$lines[] = sprintf('add    mapping %s on poller %d -> device %d (%s, %s)', $mapping['path'], $mapping['poller_id'], $mapping['host_id'], self::show($mapping['hostname']), self::show($mapping['description']));
		}

		$lines[] = sprintf('%d unchanged', $this->unchanged);

		return $lines;
	}

	/**
	 * @return array<string, string|int|bool>
	 */
	public static function fields(Target $target): array {
		return [
			'poller_id'    => $target->pollerId,
			'path'         => $target->path->value,
			'host'         => $target->host,
			'probe'        => $target->probe,
			'menu'         => $target->menu,
			'title'        => $target->title,
			'slaves'       => implode(' ', $target->slaves),
			'nomasterpoll' => $target->noMasterPoll,
			'step'         => $target->step,
			'pings'        => $target->pings,
			'mode'         => $target->mode->value,
			'admin_state'  => $target->adminState->value,
			'present'      => $target->present,
		];
	}

	private static function show(string|int|bool|null $value): string {
		if ($value === null) {
			return '(unset)';
		}

		return is_bool($value) ? ($value ? 'true' : 'false') : (is_int($value) ? (string) $value : '"' . addcslashes($value, "\"\\") . '"');
	}
}
