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
 * Confirmation screens of the inventory page. Nothing is created from a link:
 * each screen says what the action does and posts back only when the
 * administrator submits it.
 */
final class InventoryForms {
	/**
	 * @param array<int, string> $devices device id => description the user may map to
	 */
	public static function add(Target $target, array $devices): void {
		html_start_box(html_escape(__('Add %s to Cacti', $target->path->value, 'smokeping')), '100%', '', '3', 'center', '');
		form_start('smokeping_inventory.php', 'smokeping_add');

		print '<tr><td class="textArea">' . html_escape(__('SmokePing target %s (host %s) has no Cacti device. Choose what to do with it.', $target->path->value, $target->host, 'smokeping')) . '</td></tr>';
		print '<tr><td><label><input type="radio" name="mode" value="create" checked> ' . html_escape(__('Create a new device from the SmokePing Target template', 'smokeping')) . '</label></td></tr>';
		print '<tr><td><label><input type="radio" name="mode" value="map"> ' . html_escape(__('Map it to an existing device', 'smokeping')) . '</label><br>';

		form_dropdown('host_id', $devices, '', '', '', '', '0');

		print '</td></tr><tr><td class="textArea">' . html_escape(__('Either way the target gets a summary graph for alerting and, when SmokePing\'s data can be graphed, a smoke graph. Cacti never writes SmokePing\'s files.', 'smokeping')) . '</td></tr>';
		print '<tr><td class="saveRow"><input type="hidden" name="action" value="add_confirm"><input type="hidden" name="id" value="' . (int) $target->id?->value . '">';
		print '<input type="button" class="ui-button ui-corner-all ui-widget cactiReturnTo" value="' . html_escape(__('Cancel', 'smokeping')) . '"> <input type="submit" class="ui-button ui-corner-all ui-widget" value="' . html_escape(__('Add', 'smokeping')) . '"></td></tr>';

		form_end();
		html_end_box(false);
	}

	/**
	 * Relink.
	 *
	 * @param int $proposalId Proposal id.
	 * @param Target $old Old.
	 * @param Target $new New.
	 * @param int $hostId Host id.
	 */
	public static function relink(int $proposalId, Target $old, Target $new, int $hostId): void {
		html_start_box(html_escape(__('Confirm Re-link', 'smokeping')), '100%', '', '3', 'center', '');
		form_start('smokeping_inventory.php', 'smokeping_relink');

		print '<tr><td class="textArea"><p>' . html_escape(__('SmokePing no longer lists %s. %s has the same host (%s) and probe (%s). SmokePing starts a new, empty file for a new path.', $old->path->value, $new->path->value, $new->host, $new->probe, 'smokeping')) . '</p>';
		print '<p>' . html_escape(__('Re-linking moves the smoke graph and the summary data source to the new target and the device mapping with them. The old file keeps its history in an archived graph. Nothing is deleted.', 'smokeping')) . '</p>';

		if ($hostId > 0) {
			print '<p>' . html_escape(__('Device %d is mapped to the old target.', $hostId, 'smokeping')) . '</p>';
		}

		print '</td></tr><tr><td class="saveRow"><input type="hidden" name="id" value="' . $proposalId . '">';
		print '<input type="button" class="ui-button ui-corner-all ui-widget cactiReturnTo" value="' . html_escape(__('Cancel', 'smokeping')) . '"> ';
		print '<button type="submit" name="action" value="relink_dismiss" class="ui-button ui-corner-all ui-widget">' . html_escape(__('Dismiss Proposal', 'smokeping')) . '</button> ';
		print '<button type="submit" name="action" value="relink_confirm" class="ui-button ui-corner-all ui-widget">' . html_escape(__('Re-link', 'smokeping')) . '</button></td></tr>';

		form_end();
		html_end_box(false);
	}
}
