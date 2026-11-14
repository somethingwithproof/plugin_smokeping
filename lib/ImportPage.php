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
 * Screens of Console > Import/Export > Import SmokePing Configuration: the
 * options form, the preview of a plan with its section selection, and the
 * confirmation. Forms post to the page itself, so Cacti's CSRF check covers
 * every action. Every value is escaped here.
 */
final class ImportPage {
	private const FILE = 'smokeping_import.php';

	/** Rows of a long list shown before the rest is summarized. */
	private const MAX_ROWS = 200;

	public static function refused(string $message): void {
		html_start_box(html_escape(__('Import SmokePing Configuration', 'smokeping')), '100%', '', '3', 'center', '');
		print '<tr><td class="textArea">' . html_escape($message) . '</td></tr>';
		html_end_box(false);
	}

	/**
	 * @param array<string, mixed> $values current choices
	 * @param array<int, string> $pollers
	 * @param array<int, string> $templates
	 * @param array<int, string> $sites
	 * @param array<int, string> $trees
	 */
	public static function form(array $values, string $configFile, array $pollers, array $templates, array $sites, array $trees): void {
		form_start(self::FILE, 'smokeping_import', true);
		html_start_box(html_escape(__('Import SmokePing Configuration', 'smokeping')), '100%', true, '3', 'center', '');

		$fields = [
			'source' => [
				'friendly_name' => __('Configuration', 'smokeping'),
				'description'   => __('The configured SmokePing file is read with the files it includes, from its own directory. An uploaded file is read on its own: @include is not allowed in it.', 'smokeping'),
				'method'        => 'drop_array',
				'array'         => [
					'server' => __('The configured file, %s', $configFile, 'smokeping'),
					'upload' => __('An uploaded file, without @include', 'smokeping'),
				],
				'value'         => $values['source'],
			],
			'smokeping_file' => [
				'friendly_name' => __('Uploaded File', 'smokeping'),
				'description'   => __('Only for an uploaded file: a SmokePing configuration of at most 4 MiB.', 'smokeping'),
				'method'        => 'file',
			],
			'device_per' => [
				'friendly_name' => __('Devices', 'smokeping'),
				'description'   => __('One device per SmokePing host groups targets that probe the same host. A device that already has the host name on the chosen poller is used instead of a new one.', 'smokeping'),
				'method'        => 'drop_array',
				'array'         => [
					SmokepingImportOptions::PER_HOST   => __('One device per SmokePing host', 'smokeping'),
					SmokepingImportOptions::PER_TARGET => __('One device per SmokePing target', 'smokeping'),
				],
				'value'         => $values['device_per'],
			],
			'template' => [
				'friendly_name' => __('Device Template', 'smokeping'),
				'description'   => __('The template of devices the import creates.', 'smokeping'),
				'method'        => 'drop_array',
				'array'         => $templates,
				'value'         => $values['template'],
			],
			'poller' => [
				'friendly_name' => __('Poller', 'smokeping'),
				'description'   => __('The poller of new devices. It must be the poller that runs the SmokePing reader, or their summary data sources get no data.', 'smokeping'),
				'method'        => 'drop_array',
				'array'         => $pollers,
				'value'         => $values['poller'],
			],
			'site' => [
				'friendly_name' => __('Site', 'smokeping'),
				'description'   => __('The site of new devices.', 'smokeping'),
				'method'        => 'drop_array',
				'array'         => [0 => __('None', 'smokeping')] + $sites,
				'value'         => $values['site'],
			],
			'tree' => [
				'friendly_name' => __('Graph Tree', 'smokeping'),
				'description'   => __('Mirror the SmokePing hierarchy into a graph tree: each section becomes a branch, and each device a host under the branch of its targets. Existing branches and hosts are reused.', 'smokeping'),
				'method'        => 'drop_array',
				'array'         => [0 => __('Do not mirror', 'smokeping'), -1 => __('A new tree, named below', 'smokeping')] + $trees,
				'value'         => $values['tree'],
			],
			'tree_name' => [
				'friendly_name' => __('New Tree Name', 'smokeping'),
				'description'   => __('Only for a new tree. A tree that already has this name is used.', 'smokeping'),
				'method'        => 'textbox',
				'max_length'    => '255',
				'size'          => '40',
				'value'         => $values['tree_name'],
			],
		];

		draw_edit_form(['config' => ['no_form_tag' => true], 'fields' => $fields]);

		html_end_box(true, true);

		html_start_box('', '100%', '', '3', 'center', '');
		print '<tr><td class="textArea">' . html_escape(__('Preview reads the configuration and lists what the import would create. Nothing is written until you confirm that list on the next screen, and SmokePing\'s own files are never written.', 'smokeping')) . '</td></tr>';
		html_end_box(false);

		form_hidden_box('action', 'preview', '');
		form_save_buttons([['id' => 'preview', 'value' => __('Preview', 'smokeping')]]);
		form_end(false);
	}

	/**
	 * @param array<string, string> $hidden the options, carried to the next step
	 */
	public static function preview(SmokepingImportPlan $plan, array $hidden): void {
		$counts = $plan->counts();

		if ($plan->errors !== [] || $plan->notes !== []) {
			$rows = [];

			foreach ($plan->errors as $error) {
				$rows[] = [__('Error', 'smokeping'), $error];
			}

			foreach ($plan->notes as $note) {
				$rows[] = [__('Note', 'smokeping'), $note];
			}

			Html::table(__('Import Check', 'smokeping'), [__('Level', 'smokeping'), __('Message', 'smokeping')], $rows, '');
		}

		form_start(self::FILE, 'smokeping_import_sections');
		html_start_box(html_escape(__('SmokePing Sections', 'smokeping')), '100%', '', '3', 'center', '');

		print '<tr class="tableHeader"><th class="left">' . html_escape(__('Import', 'smokeping')) . '</th><th class="left">' . html_escape(__('Section', 'smokeping')) . '</th><th class="left">' . html_escape(__('Targets', 'smokeping')) . '</th></tr>';

		$chosen = $plan->options->sections;

		foreach ($plan->sections as $section => $count) {
			$depth = substr_count($section, '/');
			$id    = 'section_' . substr(hash('sha256', $section), 0, 12);

			print '<tr class="selectable tableRow"><td><input type="checkbox" id="' . $id . '" name="sections[]" value="' . html_escape($section) . '"' . (in_array($section, $chosen, true) ? ' checked' : '') . '></td>';
			print '<td style="padding-left:' . (4 + 16 * $depth) . 'px"><label for="' . $id . '">' . html_escape($section) . '</label></td><td>' . $count . '</td></tr>';
		}

		print '<tr><td class="textArea" colspan="3">' . html_escape($chosen === [] ? __('No section is checked, so every target is selected. Check sections and update the preview to import only those.', 'smokeping') : __('Only targets in the checked sections are selected.', 'smokeping')) . '</td></tr>';

		html_end_box(false);

		foreach ($hidden as $name => $value) {
			form_hidden_box($name, $value, '');
		}

		form_hidden_box('action', 'preview', '');
		form_save_buttons([['id' => 'update', 'value' => __('Update Preview', 'smokeping')]]);
		form_end(false);

		$rows = [];

		foreach (array_slice($plan->devices, 0, self::MAX_ROWS) as $device) {
			$rows[] = [
				match ($device['action']) {
					'create' => __('Create', 'smokeping'),
					'map'    => __('Use device %d', $device['host_id'], 'smokeping'),
					'mapped' => __('Already mapped to device %d', $device['host_id'], 'smokeping'),
					default  => __('Skip: %s', $device['reason'], 'smokeping'),
				},
				$device['description'],
				$device['hostname'],
				self::summarize($device['targets']),
			];
		}

		Html::table(
			__('Devices: %d to create, %d existing to use, %d already mapped, %d skipped', $counts['create'], $counts['map'], $counts['mapped'], $counts['ambiguous'], 'smokeping'),
			[__('Action', 'smokeping'), __('Description', 'smokeping'), __('Host Name', 'smokeping'), __('SmokePing Targets', 'smokeping')],
			$rows,
			__('No device.', 'smokeping')
		);

		$rows = [];

		foreach ($plan->graphs as $graph) {
			if (!$graph['exists'] && count($rows) < self::MAX_ROWS) {
				$rows[] = [$graph['kind'] === 'smoke' ? __('Smoke', 'smokeping') : __('Summary', 'smokeping'), $graph['path'], $graph['location'] === '' ? __('master', 'smokeping') : $graph['location']];
			}
		}

		Html::table(
			__('Graphs: %d to create, %d already exist', $counts['graphs'], $counts['existing'], 'smokeping'),
			[__('Graph', 'smokeping'), __('SmokePing Target', 'smokeping'), __('Location', 'smokeping')],
			$rows,
			__('No graph to create.', 'smokeping')
		);

		if ($plan->summaryOnly !== []) {
			$rows = [];

			foreach (array_slice($plan->summaryOnly, 0, self::MAX_ROWS, true) as $path => $reason) {
				$rows[] = [(string) $path, $reason];
			}

			Html::table(__('Summary Data Only: %d targets', count($plan->summaryOnly), 'smokeping'), [__('SmokePing Target', 'smokeping'), __('Reason', 'smokeping')], $rows, '');
		}

		if ($plan->options->mirrorsTree()) {
			$rows = [];

			foreach (array_slice($plan->leaves, 0, self::MAX_ROWS) as $leaf) {
				$rows[] = [$leaf['branch'] === '' ? '/' : $leaf['branch'], $leaf['device']];
			}

			Html::table(__('Graph Tree: %d branches, %d device entries', count($plan->branches), count($plan->leaves), 'smokeping'), [__('Branch', 'smokeping'), __('Device', 'smokeping')], $rows, '');
		}

		Html::table(__('Estimated Load', 'smokeping'), [__('Estimate', 'smokeping')], array_map(fn (string $line) => [$line], $plan->load->lines()), '');

		html_start_box(html_escape(__('Confirm', 'smokeping')), '100%', '', '3', 'center', '');

		if ($plan->hasErrors()) {
			print '<tr><td class="textArea">' . html_escape(__('The configuration cannot be imported until the errors above are fixed. Nothing was written.', 'smokeping')) . '</td></tr>';
			print '<tr><td><a class="linkEditMain" href="' . self::FILE . '">' . html_escape(__('Back', 'smokeping')) . '</a></td></tr>';
			html_end_box(false);

			return;
		}

		if ($plan->isEmpty()) {
			print '<tr><td class="textArea">' . html_escape(__('Every selected target is already in Cacti. There is nothing to import.', 'smokeping')) . '</td></tr>';
			print '<tr><td><a class="linkEditMain" href="' . self::FILE . '">' . html_escape(__('Back', 'smokeping')) . '</a></td></tr>';
			html_end_box(false);

			return;
		}

		form_start(self::FILE, 'smokeping_import_apply');

		print '<tr><td class="textArea">' . html_escape(__('Plan %s. Importing creates exactly what is listed above through Cacti\'s device, graph, and tree functions. If the SmokePing configuration or Cacti changed since this preview, the import is refused and you preview again.', $plan->id(), 'smokeping')) . '</td></tr>';
		print '<tr><td class="saveRow">';

		foreach ($hidden as $name => $value) {
			form_hidden_box($name, $value, '');
		}

		foreach ($plan->options->sections as $section) {
			print '<input type="hidden" name="sections[]" value="' . html_escape($section) . '">';
		}

		form_hidden_box('action', 'apply', '');
		form_hidden_box('plan', $plan->id(), '');

		print '<input type="button" class="ui-button ui-corner-all ui-widget" onClick="document.location=\'' . self::FILE . '\'" value="' . html_escape(__('Cancel', 'smokeping')) . '"> ';
		print '<input type="submit" class="ui-button ui-corner-all ui-widget" value="' . html_escape(__('Import', 'smokeping')) . '"></td></tr>';

		form_end(false);
		html_end_box(false);
	}

	/**
	 * @param list<string> $items
	 */
	private static function summarize(array $items): string {
		if (count($items) <= 5) {
			return implode(', ', $items);
		}

		return __('%s and %d more', implode(', ', array_slice($items, 0, 5)), count($items) - 5, 'smokeping');
	}
}
