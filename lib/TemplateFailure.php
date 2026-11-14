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
 * A recorded failure of the template import, kept in one setting so the health
 * page can show it and so a failed import is not retried on every request.
 *
 * The import is retried when the plugin version or the template file version
 * differs from the one that failed, on install, and on an administrator's
 * explicit request.
 */
final readonly class TemplateFailure {
	public function __construct(
		public string $pluginVersion,
		public int $templatesVersion,
		public int $at,
		public string $message,
	) {
	}

	public static function fromSetting(string $value): ?self {
		if ($value === '') {
			return null;
		}

		$data = json_decode($value, true);

		if (!is_array($data)) {
			return null;
		}

		return new self(Row::string($data, 'plugin'), Row::int($data, 'templates', 0, 0), Row::int($data, 'at', 0, 0), Row::string($data, 'message'));
	}

	public function toSetting(): string {
		return json_encode(['plugin' => $this->pluginVersion, 'templates' => $this->templatesVersion, 'at' => $this->at, 'message' => $this->message], JSON_THROW_ON_ERROR);
	}

	/**
	 * True when retrying now would repeat the import that already failed.
	 */
	public function blocks(string $pluginVersion, int $templatesVersion): bool {
		return $this->pluginVersion === $pluginVersion && $this->templatesVersion === $templatesVersion;
	}
}
