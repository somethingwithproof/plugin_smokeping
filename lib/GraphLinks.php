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
 * The link from a device's graphs to its SmokePing targets, drawn beside the
 * graph by the graph_buttons hook. It appears only for a graph of a device
 * that has SmokePing targets and only for users who may open the SmokePing
 * page, which lists nothing the user's device permissions do not allow.
 */
final class GraphLinks {
	public function __construct(
		private readonly TargetRepository $targets,
		private readonly CactiObjectRepository $cacti,
	) {
	}

	/**
	 * The device whose SmokePing page the graph links to, or 0 for no link.
	 */
	public function deviceFor(int $localGraphId): int {
		if ($localGraphId < 1) {
			return 0;
		}

		$hostId = $this->cacti->deviceOfGraph($localGraphId);

		return $hostId > 0 && $this->targets->deviceHasTargets($hostId) ? $hostId : 0;
	}

	/**
	 * A keyboard focus ring for the link, printed once per page. Cacti's
	 * themes draw graph buttons' focus as a thin dark outline, which does not
	 * show on the dark themes; a white ring inside a black one shows on any
	 * background.
	 */
	public static function style(): string {
		return '<style>a.smokepingGraphLink:focus-visible{outline:2px solid #fff;outline-offset:0;box-shadow:0 0 0 4px #000}</style>';
	}

	public static function html(string $urlPath, int $hostId): string {
		$url = $urlPath . 'plugins/smokeping/smokeping.php?host_id=' . $hostId;

		return '<a class="iconLink smokepingGraphLink" role="link" href="' . html_escape($url) . '" title="' . html_escape(__('SmokePing Targets of this Device', 'smokeping')) . '" aria-label="' . html_escape(__('SmokePing Targets of this Device', 'smokeping')) . '"><img class="drillDown" src="' . html_escape($urlPath . 'images/server_chart_curve.png') . '" alt=""></a><br/>';
	}
}
