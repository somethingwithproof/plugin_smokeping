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
	 * Write a YAML export of the configuration and return its path, or null
	 * when nothing was written. Used by the uninstall; a failure is logged and
	 * never blocks it.
	 *
	 * With a backup directory configured, the file goes there and nowhere else:
	 * a directory that is missing or not writable means no export, never a quiet
	 * fall back to the default, which the administrator did not choose. Without
	 * one, the file goes to $defaultDir, which is under the web root, so the
	 * directory gets a deny-all .htaccess and an index.php that serves nothing,
	 * and is created only when both can be written. The file has a random name
	 * and is readable by its owner only.
	 */
	public function writeBackup(string $defaultDir, string $customDir, int $now): ?string {
		try {
			$custom = rtrim(trim($customDir), '/');

			if ($custom !== '') {
				if (!is_dir($custom) || !is_writable($custom)) {
					throw new ConfigException(sprintf('The backup directory %s is not a writable directory; no export was written', $custom));
				}

				$directory = $custom;
			} else {
				$directory = rtrim($defaultDir, '/');

				self::guardDirectory($directory);
			}

			$text = $this->export(ConfigCodec::YAML);
			$file = $directory . '/smokeping-config-' . gmdate('Ymd-His', $now) . '-' . bin2hex(random_bytes(8)) . '.yaml';

			$previous = umask(0077);
			$handle   = @fopen($file, 'x');

			umask($previous);

			if ($handle === false || fwrite($handle, $text) === false || !fclose($handle) || !chmod($file, 0600)) {
				throw new ConfigException(sprintf('Cannot write %s', $file));
			}

			return $file;
		} catch (\Throwable $e) {
			Log::error('The configuration export was not written: ' . $e->getMessage());

			return null;
		}
	}

	/**
	 * Create the default backup directory with the files that keep a web
	 * server from serving it. Refuses unless both exist afterwards.
	 */
	private static function guardDirectory(string $directory): void {
		if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
			throw new ConfigException(sprintf('Cannot create %s', $directory));
		}

		$guards = [
			'.htaccess' => "# Configuration exports: no access from the web.\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tOrder deny,allow\n\tDeny from all\n</IfModule>\n",
			'index.php' => "<?php\nhttp_response_code(403);\nexit;\n",
		];

		foreach ($guards as $name => $content) {
			if ((!is_file($directory . '/' . $name) || file_get_contents($directory . '/' . $name) !== $content) && file_put_contents($directory . '/' . $name, $content) === false) {
				throw new ConfigException(sprintf('Cannot write %s/%s', $directory, $name));
			}
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
