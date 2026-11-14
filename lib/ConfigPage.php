<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Thomas Vincent

namespace Somethingwithproof\Smokeping;

/**
 * Screens of the configuration page: export, the check of an uploaded file,
 * and the confirmation of its plan. Forms post to the page itself, so
 * Cacti's CSRF check covers every action.
 */
final class ConfigPage {
	/**
	 * @return array<string, string>
	 */
	public static function formats(): array {
		return [ConfigCodec::YAML => __('YAML (default)', 'smokeping'), ConfigCodec::XML => __('XML', 'smokeping')];
	}

	public static function refused(): void {
		html_start_box(html_escape(__('SmokePing Configuration', 'smokeping')), '100%', '', '3', 'center', '');

		print '<tr><td class="textArea">' . html_escape(__('Your device permissions do not cover every device, so you cannot export or import the SmokePing configuration. Ask an administrator with access to all devices.', 'smokeping')) . '</td></tr>';

		html_end_box(false);
	}

	public static function main(): void {
		html_start_box(html_escape(__('Export the SmokePing Configuration', 'smokeping')), '100%', '', '3', 'center', '');
		form_start('smokeping_config.php', 'smokeping_export');

		print '<tr><td class="textArea">' . html_escape(__('The export holds the plugin settings, the SmokePing targets the plugin has read, and which device each is mapped to, by the device\'s host name and description. It holds no data source, graph, RRD file, or credential.', 'smokeping')) . '</td></tr>';
		print '<tr><td>';

		form_dropdown('format', self::formats(), '', '', ConfigCodec::YAML, '', ConfigCodec::YAML);

		print ' <input type="hidden" name="action" value="export"><input type="submit" class="ui-button ui-corner-all ui-widget" value="' . html_escape(__('Download', 'smokeping')) . '"></td></tr>';

		form_end(false);
		html_end_box(false);

		html_start_box(html_escape(__('Import a SmokePing Configuration', 'smokeping')), '100%', '', '3', 'center', '');
		form_start('smokeping_config.php', 'smokeping_check', true);

		print '<tr><td class="textArea">' . html_escape(__('Choosing a file only checks it. The next screen lists every change the file would make, and nothing is written until you confirm that list. An import adds and updates; it never deletes a target or moves a mapping, and it never creates an RRD file or touches the SmokePing data directory.', 'smokeping')) . '</td></tr>';
		print '<tr><td>';

		form_dropdown('format', self::formats(), '', '', ConfigCodec::YAML, '', ConfigCodec::YAML);

		print ' <input type="file" name="config_file" required> <input type="hidden" name="action" value="check"><input type="submit" class="ui-button ui-corner-all ui-widget" value="' . html_escape(__('Check File', 'smokeping')) . '"></td></tr>';

		form_end(false);
		html_end_box(false);
	}

	/**
	 * @param list<string> $problems problems with the file itself, when it could not be read
	 */
	public static function plan(?ConfigPlan $plan, array $problems): void {
		html_start_box(html_escape(__('Import Check', 'smokeping')), '100%', '', '3', 'center', '');

		if ($plan === null) {
			foreach ($problems as $problem) {
				print '<tr><td>' . html_escape($problem) . '</td></tr>';
			}

			print '<tr><td class="textArea">' . html_escape(__('Nothing was written.', 'smokeping')) . '</td></tr>';
			print '<tr><td><a class="linkEditMain" href="smokeping_config.php">' . html_escape(__('Back', 'smokeping')) . '</a></td></tr>';
			html_end_box(false);

			return;
		}

		foreach ($plan->lines() as $line) {
			print '<tr><td><code>' . html_escape($line) . '</code></td></tr>';
		}

		if ($plan->hasErrors()) {
			print '<tr><td class="textArea">' . html_escape(__('The file cannot be imported until these errors are fixed. Nothing was written.', 'smokeping')) . '</td></tr>';
			print '<tr><td><a class="linkEditMain" href="smokeping_config.php">' . html_escape(__('Back', 'smokeping')) . '</a></td></tr>';
		} elseif ($plan->isEmpty()) {
			print '<tr><td class="textArea">' . html_escape(__('The installation already matches the file. There is nothing to import.', 'smokeping')) . '</td></tr>';
			print '<tr><td><a class="linkEditMain" href="smokeping_config.php">' . html_escape(__('Back', 'smokeping')) . '</a></td></tr>';
		} else {
			form_start('smokeping_config.php', 'smokeping_apply');

			print '<tr><td class="textArea">' . html_escape(__('Plan %s. Applying makes exactly these changes in one transaction. If the file or the installation changed since this check, the import is refused and you check again.', $plan->id(), 'smokeping')) . '</td></tr>';
			print '<tr><td class="saveRow"><input type="hidden" name="action" value="apply"><input type="hidden" name="plan" value="' . html_escape($plan->id()) . '">';
			print '<input type="button" class="ui-button ui-corner-all ui-widget cactiReturnTo" value="' . html_escape(__('Cancel', 'smokeping')) . '"> <input type="submit" class="ui-button ui-corner-all ui-widget" value="' . html_escape(__('Apply Import', 'smokeping')) . '"></td></tr>';

			form_end(false);
		}

		html_end_box(false);
	}
}
