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
 * Imports the shipped data, graph, and device templates with Cacti's own
 * import_xml_data().
 *
 * Every template carries a hash, so import updates the objects it made earlier
 * instead of adding copies. The file is Cacti's export of templates built in a
 * Cacti 1.2.31 instance (tests/fidelity/build-templates.php); it is never
 * edited by hand. The version setting records which export was imported, so an
 * upgrade re-imports only when the file changed.
 */
final class TemplateInstaller {
	public const VERSION = 2;

	public const SETTING = 'smokeping_templates_version';

	public const FAILURE_SETTING = 'smokeping_templates_failure';

	public const DEVICE_TEMPLATE = 'SmokePing Target';

	public const SUMMARY_TEMPLATE = 'SmokePing Summary';

	public const SUMMARY_INPUT = 'SmokePing Summary Script';

	/** Ping counts that have a smoke template. */
	public const PINGS = [10, 20, 30];

	public function __construct(
		private readonly string $file,
		private readonly string $coreLibrary,
		private readonly CactiObjectRepository $objects,
	) {
	}

	public static function graphTemplateName(int $pings): string {
		return sprintf('SmokePing Smoke (%d pings)', $pings);
	}

	public function isCurrent(): bool {
		return (int) read_config_option(self::SETTING) === self::VERSION;
	}

	public function failure(): ?TemplateFailure {
		return TemplateFailure::fromSetting((string) read_config_option(self::FAILURE_SETTING));
	}

	/**
	 * Whether an upgrade or request may start an import: not when this plugin
	 * version already failed to import this template file. Installing and the
	 * administrator's retry bypass the check by calling install() directly.
	 */
	public function mayRetry(string $pluginVersion): bool {
		$failure = $this->failure();

		return $failure === null || !$failure->blocks($pluginVersion, self::VERSION);
	}

	/**
	 * Import once. A failure is logged once and recorded for the health page;
	 * success clears any recorded failure.
	 *
	 * @return bool true when every template object was imported
	 */
	public function install(string $pluginVersion): bool {
		$error = $this->import();

		if ($error !== null) {
			Log::error($error);
			set_config_option(self::FAILURE_SETTING, (new TemplateFailure($pluginVersion, self::VERSION, time(), $error))->toSetting());

			return false;
		}

		set_config_option(self::SETTING, (string) self::VERSION);
		set_config_option(self::FAILURE_SETTING, '');

		return true;
	}

	/**
	 * @return string|null the reason the import failed, or null when it succeeded
	 */
	private function import(): ?string {
		$xml = is_readable($this->file) ? file_get_contents($this->file) : false;

		if (!is_string($xml) || $xml === '') {
			return 'The template file ' . $this->file . ' is missing or empty';
		}

		// Importing a data input method replicates it to remote collectors.
		include_once($this->coreLibrary . '/utility.php');
		include_once($this->coreLibrary . '/import.php');

		$result = import_xml_data($xml, false, $this->objects->defaultProfileId());

		if (!is_array($result) || $result === []) {
			return 'Importing the SmokePing templates returned nothing';
		}

		$failed = 0;

		foreach ($result as $type => $entries) {
			if ($type === 'files' || !is_array($entries)) {
				continue;
			}

			foreach ($entries as $entry) {
				if (is_array($entry) && ($entry['result'] ?? '') === 'fail') {
					$failed++;
				}
			}
		}

		if ($failed > 0) {
			return sprintf('%d SmokePing template objects failed to import', $failed);
		}

		if (!$this->present()) {
			return 'The SmokePing templates are missing after import';
		}

		// Import only adds graph templates to a device template, so links left
		// by an earlier template file survive it. Cacti would create a graph
		// with no data source for each one on every new SmokePing device.
		$this->objects->unlinkDeviceTemplateGraphs($this->objects->deviceTemplateId(self::DEVICE_TEMPLATE));

		return null;
	}

	/**
	 * True when the device template, every smoke graph template, and the summary
	 * templates exist.
	 */
	public function present(): bool {
		if ($this->objects->deviceTemplateCount(self::DEVICE_TEMPLATE) !== 1) {
			return false;
		}

		if ($this->objects->graphTemplateCount(self::SUMMARY_TEMPLATE) !== 1 || $this->objects->dataTemplateCount(self::SUMMARY_TEMPLATE) !== 1 || $this->objects->dataInputCount(self::SUMMARY_INPUT) !== 1) {
			return false;
		}

		foreach (self::PINGS as $pings) {
			if ($this->objects->graphTemplateCount(self::graphTemplateName($pings)) !== 1) {
				return false;
			}
		}

		return true;
	}
}
