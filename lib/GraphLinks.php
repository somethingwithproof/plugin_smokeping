<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Thomas Vincent

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

	public static function html(string $urlPath, int $hostId): string {
		$url = $urlPath . 'plugins/smokeping/smokeping.php?host_id=' . $hostId;

		return '<a class="iconLink" role="link" href="' . html_escape($url) . '" title="' . html_escape(__('SmokePing Targets of this Device', 'smokeping')) . '" aria-label="' . html_escape(__('SmokePing Targets of this Device', 'smokeping')) . '"><img class="drillDown" src="' . html_escape($urlPath . 'images/server_chart_curve.png') . '" alt=""></a><br/>';
	}
}
