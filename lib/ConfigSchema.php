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
 * The configuration file schema, version 1, and the check that turns a parsed
 * file into a ConfigDocument. docs/configuration-format.md describes it.
 *
 * Both formats reach this class as the same tree, so one set of rules covers
 * them. Every problem found is reported, not only the first, and an unknown
 * key is an error: a typo must not silently drop a setting. The keys
 * managed_targets and probe_profiles are reserved for the release that adds
 * managed mode and are refused here, so a file written for that release is
 * never half applied by this one.
 */
final class ConfigSchema {
	public const RESERVED = ['managed_targets', 'probe_profiles'];

	/**
	 * Settings of earlier releases that no longer exist. A file exported by
	 * such a release lists them under reference_settings, where they were
	 * never imported; they are skipped there so the file still imports.
	 */
	public const RETIRED = ['smokeping_perl_binary', 'smokeping_lib_dir'];

	public const MAX_ERRORS = 50;

	/**
	 * A setting that names a file, a directory, or a program is reference
	 * only: a file can show it, but an import never writes it. Letting a file
	 * set the interpreter the reader runs, or where the plugin reads and writes,
	 * would turn an import into a way to run code or reach files, so the
	 * property follows from the setting's type, not from a list a caller keeps.
	 */
	public static function isReferenceOnly(string $name): bool {
		return in_array(self::SETTINGS[$name][0] ?? '', ['path', 'optional_path'], true);
	}

	/**
	 * Type, and for ints the lowest accepted value, of each setting.
	 *
	 * @var array<string, array{0: string, 1?: int}>
	 */
	public const SETTINGS = [
		'smokeping_config_file'          => ['path'],
		'smokeping_datadir'              => ['path'],
		'smokeping_backup_dir'           => ['optional_path'],
		'smokeping_mount_path'           => ['path'],
		'smokeping_poller_id'            => ['int', 1],
		'smokeping_reader_budget'        => ['int', 1],
		'smokeping_inventory_interval'   => ['int', 0],
		'smokeping_freshness_allowance'  => ['int', 0],
		'smokeping_uninstall_keep_tables' => ['bool'],
	];

	/**
	 * Types of the fields of a target entry, for the XML reader.
	 *
	 * @var array<string, string>
	 */
	public const TARGET_TYPES = [
		'poller_id' => 'int', 'step' => 'int', 'pings' => 'int',
		'nomasterpoll' => 'bool', 'present' => 'bool',
	];

	private const TARGET_KEYS = ['path', 'poller_id', 'host', 'probe', 'menu', 'title', 'slaves', 'nomasterpoll', 'step', 'pings', 'mode', 'admin_state', 'present'];

	/** @var list<string> */
	private array $errors = [];

	private function __construct(private readonly ConfigTree $tree) {
	}

	public static function build(ConfigTree $tree): ConfigDocument {
		$schema   = new self($tree);
		$document = $schema->document();

		if ($schema->errors !== []) {
			$count = count($schema->errors);

			throw new ConfigException(sprintf('The file is not valid: %d problem%s', $count, $count === 1 ? '' : 's'), null, $schema->errors);
		}

		return $document;
	}

	/**
	 * Convert text from XML to the type the schema expects. Text that does not
	 * look like its type stays a string, and the check reports it.
	 */
	public static function coerce(string $type, string $text): int|bool|string {
		if ($type === 'int' && preg_match('/^-?[0-9]{1,18}$/', $text) === 1) {
			return (int) $text;
		}

		if ($type === 'bool' && ($text === 'true' || $text === 'false')) {
			return $text === 'true';
		}

		return $text;
	}

	private function document(): ConfigDocument {
		$data = $this->tree->data;

		if (array_is_list($data) && $data !== []) {
			$this->error('', 'the document must be a map with schema_version, settings, targets, and mappings');

			return new ConfigDocument([], [], []);
		}

		foreach (array_keys($data) as $key) {
			$key = (string) $key;

			if (in_array($key, self::RESERVED, true)) {
				$this->error($key, 'is reserved for managed mode, which this version does not support; remove it');
			} elseif (!in_array($key, ['schema_version', 'settings', 'reference_settings', 'targets', 'mappings'], true)) {
				$this->error($key, 'is not a known key');
			}
		}

		$version = $data['schema_version'] ?? null;

		if (!is_int($version)) {
			$this->error('schema_version', 'is required and must be the integer ' . ConfigDocument::VERSION);
		} elseif ($version > ConfigDocument::VERSION) {
			$this->error('schema_version', sprintf('%d is newer than this version understands (%d); upgrade the plugin', $version, ConfigDocument::VERSION));
		} elseif ($version < 1) {
			$this->error('schema_version', 'must be 1 or higher');
		}

		$settings  = $this->settings($data['settings'] ?? null, false);
		$reference = $this->settings($data['reference_settings'] ?? null, true);
		$targets   = $this->targets($data['targets'] ?? null);

		return new ConfigDocument($settings, $targets, $this->mappings($data['mappings'] ?? null), $reference);
	}

	/**
	 * @return array<string, string|int|bool>
	 */
	private function settings(mixed $value, bool $reference): array {
		$section = $reference ? 'reference_settings' : 'settings';

		if ($value === null) {
			return [];
		}

		if (!is_array($value) || ($value !== [] && array_is_list($value))) {
			$this->error($section, 'must be a map of setting names to values');

			return [];
		}

		$settings = [];

		foreach ($value as $name => $raw) {
			$name = (string) $name;
			$path = $section . '/' . $name;
			$spec = self::SETTINGS[$name] ?? null;

			if ($spec === null && $reference && in_array($name, self::RETIRED, true)) {
				continue;
			}

			if ($spec === null) {
				$this->error($path, 'is not a known setting');

				continue;
			}

			if (self::isReferenceOnly($name) !== $reference) {
				$this->error($path, $reference ? 'is not a reference-only setting; put it under settings' : 'is reference only and is never imported; change it on the Settings page, and keep it under reference_settings');

				continue;
			}

			if ($spec[0] === 'int') {
				if (!is_int($raw) || $raw < ($spec[1] ?? 0) || $raw > 2147483647) {
					$this->error($path, sprintf('must be a whole number from %d to 2147483647', $spec[1] ?? 0));

					continue;
				}

				$settings[$name] = $raw;
			} elseif ($spec[0] === 'bool') {
				if (!is_bool($raw)) {
					$this->error($path, 'must be true or false');

					continue;
				}

				$settings[$name] = $raw;
			} else {
				$text = $this->text($path, $raw, 255, $spec[0] === 'optional_path');

				if ($text === null) {
					continue;
				}

				if ($text !== '' && $text[0] !== '/') {
					$this->error($path, 'must be an absolute path or empty where allowed');

					continue;
				}

				$settings[$name] = $text;
			}
		}

		return $settings;
	}

	/**
	 * @return list<Target>
	 */
	private function targets(mixed $value): array {
		if ($value === null) {
			return [];
		}

		if (!is_array($value) || ($value !== [] && !array_is_list($value))) {
			$this->error('targets', 'must be a list of targets');

			return [];
		}

		$targets = [];
		$seen    = [];

		foreach ($value as $i => $entry) {
			$path = 'targets/' . $i;

			if (!is_array($entry) || ($entry !== [] && array_is_list($entry))) {
				$this->error($path, 'must be a map');

				continue;
			}

			foreach (array_keys($entry) as $key) {
				if (!in_array((string) $key, self::TARGET_KEYS, true)) {
					$this->error($path . '/' . $key, 'is not a known target field');
				}
			}

			$target = $this->target($path, $entry);

			if ($target === null) {
				continue;
			}

			$key = ConfigState::targetKey($target->pollerId, $target->path->value);

			if (isset($seen[$key])) {
				$this->error($path, sprintf('repeats target %s on poller %d', $target->path->value, $target->pollerId));

				continue;
			}

			$seen[$key] = true;
			$targets[]  = $target;
		}

		return $targets;
	}

	/**
	 * @param array<array-key, mixed> $entry
	 */
	private function target(string $path, array $entry): ?Target {
		$before = count($this->errors);

		$raw = $entry['path'] ?? null;
		$id  = null;

		if (!is_string($raw) || $raw === '') {
			$this->error($path . '/path', 'is required and must be the target path, such as Europe/Paris');
		} else {
			try {
				$id = TargetPath::fromString($raw);
			} catch (\InvalidArgumentException $e) {
				$this->error($path . '/path', $e->getMessage());
			}
		}

		$poller = $this->int($path . '/poller_id', $entry['poller_id'] ?? 1, 1, 2147483647);
		$host   = $this->text($path . '/host', $entry['host'] ?? '', 255, true);
		$probe  = $this->text($path . '/probe', $entry['probe'] ?? '', 64, true);
		$menu   = $this->text($path . '/menu', $entry['menu'] ?? '', 255, true);
		$title  = $this->text($path . '/title', $entry['title'] ?? '', 255, true);
		$step   = $this->int($path . '/step', $entry['step'] ?? 0, 0, 604800);
		$pings  = $this->int($path . '/pings', $entry['pings'] ?? 0, 0, 10000);

		$flags = [];

		foreach (['nomasterpoll' => false, 'present' => true] as $name => $default) {
			$flag = $entry[$name] ?? $default;

			if (!is_bool($flag)) {
				$this->error($path . '/' . $name, 'must be true or false');
				$flag = $default;
			}

			$flags[$name] = $flag;
		}

		$slaves = $this->slaves($path . '/slaves', $entry['slaves'] ?? null);

		$modeText = $entry['mode'] ?? TargetMode::Observed->value;
		$mode     = is_string($modeText) ? TargetMode::tryFrom($modeText) : null;

		if ($modeText === TargetMode::Managed->value) {
			$this->error($path . '/mode', 'managed targets are not supported by this version; only observed targets can be imported');
		} elseif ($mode === null) {
			$this->error($path . '/mode', 'must be observed');
		}

		$stateText = $entry['admin_state'] ?? AdminState::Enabled->value;
		$state     = is_string($stateText) ? AdminState::tryFrom($stateText) : null;

		if ($state === null) {
			$this->error($path . '/admin_state', 'must be enabled, disabled, or archived');
		}

		if (count($this->errors) > $before || $id === null || $poller === null || $host === null || $probe === null || $menu === null || $title === null || $step === null || $pings === null || $mode === null || $state === null) {
			return null;
		}

		return new Target(null, $poller, $id, $host, $probe, $menu, $title, $slaves, $flags['nomasterpoll'], $step, $pings, $mode, $state, $flags['present'], 0, 0);
	}

	/**
	 * @return list<string>
	 */
	private function slaves(string $path, mixed $value): array {
		if ($value === null) {
			return [];
		}

		if (!is_array($value) || ($value !== [] && !array_is_list($value))) {
			$this->error($path, 'must be a list of slave names');

			return [];
		}

		$names = [];

		foreach ($value as $i => $name) {
			if (!is_string($name) || !TargetPath::isLocation($name)) {
				$this->error($path . '/' . $i, 'must be a slave name of letters, digits, dots, hyphens, and underscores, not starting with a dot');

				continue;
			}

			if (in_array($name, $names, true)) {
				$this->error($path . '/' . $i, sprintf('repeats slave %s', $name));

				continue;
			}

			$names[] = $name;
		}

		if (strlen(implode(' ', $names)) > 1024) {
			$this->error($path, 'lists more than 1024 characters of slave names');
		}

		return $names;
	}

	/**
	 * @return list<ConfigMapping>
	 */
	private function mappings(mixed $value): array {
		if ($value === null) {
			return [];
		}

		if (!is_array($value) || ($value !== [] && !array_is_list($value))) {
			$this->error('mappings', 'must be a list of mappings');

			return [];
		}

		$mappings = [];
		$seen     = [];

		foreach ($value as $i => $entry) {
			$path = 'mappings/' . $i;

			if (!is_array($entry) || ($entry !== [] && array_is_list($entry))) {
				$this->error($path, 'must be a map with target, poller_id, and device');

				continue;
			}

			foreach (array_keys($entry) as $key) {
				if (!in_array((string) $key, ['target', 'poller_id', 'device'], true)) {
					$this->error($path . '/' . $key, 'is not a known mapping field');
				}
			}

			$before = count($this->errors);
			$raw    = $entry['target'] ?? null;
			$id     = null;

			if (!is_string($raw) || $raw === '') {
				$this->error($path . '/target', 'is required and must be a target path');
			} else {
				try {
					$id = TargetPath::fromString($raw);
				} catch (\InvalidArgumentException $e) {
					$this->error($path . '/target', $e->getMessage());
				}
			}

			$poller = $this->int($path . '/poller_id', $entry['poller_id'] ?? 1, 1, 2147483647);
			$device = $entry['device'] ?? null;

			if (!is_array($device) || ($device !== [] && array_is_list($device))) {
				$this->error($path . '/device', 'is required and must have hostname and description');

				continue;
			}

			foreach (array_keys($device) as $key) {
				if (!in_array((string) $key, ['hostname', 'description'], true)) {
					$this->error($path . '/device/' . $key, 'is not a known device field');
				}
			}

			$hostname    = $this->text($path . '/device/hostname', $device['hostname'] ?? null, 250, false);
			$description = $this->text($path . '/device/description', $device['description'] ?? null, 250, false);

			if (count($this->errors) > $before || $id === null || $poller === null || $hostname === null || $description === null) {
				continue;
			}

			$mapping = new ConfigMapping($poller, $id, $hostname, $description);

			if (isset($seen[$mapping->targetKey()])) {
				$this->error($path, sprintf('maps target %s on poller %d a second time', $id->value, $poller));

				continue;
			}

			$seen[$mapping->targetKey()] = true;
			$mappings[]                  = $mapping;
		}

		return $mappings;
	}

	/**
	 * A string with no control characters, trimmed because the plugin reads
	 * every stored value trimmed. A device identity must not be empty.
	 */
	private function text(string $path, mixed $value, int $max, bool $allowEmpty): ?string {
		if (!is_string($value)) {
			$this->error($path, 'must be text' . ($allowEmpty ? '' : ' and is required'));

			return null;
		}

		$text = trim($value);

		if (preg_match('/[\x00-\x1F\x7F]/', $text) === 1) {
			$this->error($path, 'must not contain control characters');

			return null;
		}

		if (strlen($text) > $max) {
			$this->error($path, sprintf('must be at most %d bytes', $max));

			return null;
		}

		if ($text === '' && !$allowEmpty) {
			$this->error($path, 'must not be empty');

			return null;
		}

		return $text;
	}

	private function int(string $path, mixed $value, int $min, int $max): ?int {
		if (!is_int($value) || $value < $min || $value > $max) {
			$this->error($path, sprintf('must be a whole number from %d to %d', $min, $max));

			return null;
		}

		return $value;
	}

	private function error(string $path, string $message): void {
		if (count($this->errors) === self::MAX_ERRORS) {
			$this->errors[] = sprintf('more than %d problems; fix these and run the check again', self::MAX_ERRORS);
		}

		if (count($this->errors) > self::MAX_ERRORS) {
			return;
		}

		$line = $this->lineOf($path);

		$this->errors[] = ($line === null ? '' : sprintf('line %d: ', $line)) . ($path === '' ? '' : $path . ' ') . $message;
	}

	private function lineOf(string $path): ?int {
		while ($path !== '') {
			if (isset($this->tree->lines[$path])) {
				return $this->tree->lines[$path];
			}

			$cut  = strrpos($path, '/');
			$path = $cut === false ? '' : substr($path, 0, $cut);
		}

		return null;
	}
}
