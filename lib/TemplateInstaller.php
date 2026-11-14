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
	public const VERSION = 1;

	public const SETTING = 'smokeping_templates_version';

	public const DEVICE_TEMPLATE = 'SmokePing Target';

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

	/**
	 * @return bool true when every template object was imported
	 */
	public function install(): bool {
		$xml = is_readable($this->file) ? file_get_contents($this->file) : false;

		if (!is_string($xml) || $xml === '') {
			Log::error('The template file ' . $this->file . ' is missing or empty');

			return false;
		}

		include_once($this->coreLibrary . '/import.php');

		$result = import_xml_data($xml, false, $this->objects->defaultProfileId());

		if (!is_array($result) || $result === []) {
			Log::error('Importing the SmokePing templates returned nothing');

			return false;
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
			Log::error(sprintf('%d SmokePing template objects failed to import', $failed));

			return false;
		}

		if (!$this->present()) {
			Log::error('The SmokePing templates are missing after import');

			return false;
		}

		set_config_option(self::SETTING, (string) self::VERSION);

		return true;
	}

	/**
	 * True when the device template and every smoke graph template exist.
	 */
	public function present(): bool {
		if ($this->objects->deviceTemplateCount(self::DEVICE_TEMPLATE) !== 1) {
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
