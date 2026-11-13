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

final class HealthPage {
	public function __construct(
		private readonly HealthCheck $checks,
		private readonly MountCheck $mount,
		private readonly HealthRepository $health,
		private readonly TargetRepository $targets,
	) {
	}

	public function render(Settings $settings, string $rraPath, string $phpBinary): void {
		$health   = $this->health->forPoller($settings->pollerId);
		$expected = [];

		foreach ($this->targets->readable($settings->pollerId) as $target) {
			foreach ($target->locations() as $location) {
				$expected[] = $target->path->rrdFile($settings->mountPath, $location);
			}
		}

		$findings = array_merge(
			$this->checks->check($health, $phpBinary),
			$this->mount->check($settings->mountPath, $rraPath, $expected)
		);

		Html::table(
			sprintf(__('SmokePing Health for Poller %d', 'smokeping'), $settings->pollerId),
			[__('Check', 'smokeping'), __('Status', 'smokeping'), __('Detail', 'smokeping')],
			array_map(fn (Finding $f) => [$f->check, Html::level($f->level), $f->message], $findings),
			''
		);
	}
}
