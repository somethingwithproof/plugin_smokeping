<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Thomas Vincent

namespace Somethingwithproof\Smokeping;

/**
 * Markup built by this plugin from escaped parts, for a table cell that must
 * hold a link. Html::table() escapes every other cell.
 */
final readonly class RawHtml {
	public function __construct(public string $html) {
	}
}
