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
 * Export, check, and apply of the plugin's configuration file. The CLI and
 * the admin page both go through this class, so they decide the same way.
 */
final class ConfigService {
	public function __construct(private readonly ConfigRepository $repository) {
	}

	public function export(string $format): string {
		return ConfigCodec::write($format, ConfigPlanner::export($this->repository->state()));
	}

	/**
	 * Write an export under $directory and return its path, or null when it
	 * could not be written. Used when the plugin is uninstalled; a failure is
	 * logged and never blocks the uninstall. The file is readable by its owner
	 * only.
	 */
	public function exportToDirectory(string $directory, int $now): ?string {
		try {
			$text = $this->export(ConfigCodec::YAML);

			if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
				throw new ConfigException(sprintf('Cannot create %s', $directory));
			}

			$file = rtrim($directory, '/') . '/smokeping-config-' . gmdate('Ymd-His', $now) . '.yaml';

			if (file_put_contents($file, $text, LOCK_EX) === false || !chmod($file, 0600)) {
				throw new ConfigException(sprintf('Cannot write %s', $file));
			}

			return $file;
		} catch (\Throwable $e) {
			Log::error('The configuration export was not written: ' . $e->getMessage());

			return null;
		}
	}

	public function plan(ConfigDocument $document): ConfigPlan {
		return ConfigPlanner::plan($document, $this->repository->state($document->mappings));
	}

	/**
	 * Apply a document if, and only if, planning it now gives the plan the
	 * administrator confirmed.
	 *
	 * @return list<string> the reasons it was refused, empty when it was applied
	 */
	public function apply(ConfigDocument $document, string $confirmedPlanId, int $userId, int $now): array {
		$plan = $this->plan($document);

		if ($plan->hasErrors()) {
			return $plan->errors;
		}

		if (!hash_equals($plan->id(), $confirmedPlanId)) {
			return [sprintf('The file or the installation changed since the plan %s was shown; run the check again', $confirmedPlanId)];
		}

		if ($plan->isEmpty()) {
			return [];
		}

		if (!$this->repository->apply($plan, $userId, $now)) {
			return ['The import failed and was rolled back; see the SmokePing log'];
		}

		Log::info(sprintf('Configuration import applied plan %s: %d settings, %d targets added, %d changed, %d mappings added', $plan->id(), count($plan->settings), count($plan->targetAdds), count($plan->targetChanges), count($plan->mappingAdds)));

		return [];
	}
}
