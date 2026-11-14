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

	public static function html(string $urlPath, int $hostId): string {
		$url = $urlPath . 'plugins/smokeping/smokeping.php?host_id=' . $hostId;

		return '<a class="iconLink" role="link" href="' . html_escape($url) . '" title="' . html_escape(__('SmokePing Targets of this Device', 'smokeping')) . '" aria-label="' . html_escape(__('SmokePing Targets of this Device', 'smokeping')) . '"><img class="drillDown" src="' . html_escape($urlPath . 'images/server_chart_curve.png') . '" alt=""></a><br/>';
	}
}
