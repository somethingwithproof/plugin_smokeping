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
 * Filter and pagination parameters for the inventory and target view tables.
 * Values are validated and clamped at construction so repositories and pages
 * never receive arbitrary sort, state, or unbounded page sizes.
 */
final readonly class TargetFilter {
	public const DEFAULT_ROWS     = 0;
	public const DEFAULT_PAGED    = 50;
	public const MAX_ROWS         = 500;
	public const PAGE_SIZE_LABELS = [0 => 'All', 25 => '25', 50 => '50', 100 => '100', 200 => '200'];

	public const PRESENCES = ['all', 'present', 'gone'];
	public const MAPPINGS  = ['all', 'mapped', 'unmapped'];
	public const STATES    = ['all', 'current', 'delayed', 'stale', 'never'];

	public string $search;
	public string $probe;
	public string $presence;
	public string $mapping;
	public string $state;
	public int $page;
	public int $rows;

	public function __construct(
		string $search = '',
		string $probe = '',
		string $presence = 'all',
		string $mapping = 'all',
		string $state = 'all',
		int $page = 1,
		int $rows = self::DEFAULT_ROWS,
	) {
		$this->search   = mb_substr(trim($search), 0, 200);
		$this->probe    = mb_substr(trim($probe), 0, 64);
		$this->presence = in_array($presence, self::PRESENCES, true) ? $presence : 'all';
		$this->mapping  = in_array($mapping, self::MAPPINGS, true) ? $mapping : 'all';
		$this->state    = in_array($state, self::STATES, true) ? $state : 'all';
		$this->page     = max(1, $page);

		if ($rows <= 0) {
			$this->rows = $this->page > 1 ? self::DEFAULT_PAGED : 0;
		} else {
			$this->rows = min(self::MAX_ROWS, $rows);
		}
	}

	public function offset(): int {
		return $this->rows > 0 ? ($this->page - 1) * $this->rows : 0;
	}

	public function isFiltered(): bool {
		return $this->search !== ''
			|| $this->probe !== ''
			|| $this->presence !== 'all'
			|| $this->mapping !== 'all'
			|| $this->state !== 'all';
	}

	/**
	 * Query parameters carrying active filter selections for pagination and export links.
	 *
	 * @return array<string, int|string>
	 */
	public function queryParams(bool $includePage = false): array {
		$params = [];

		if ($this->search !== '') {
			$params['filter'] = $this->search;
		}

		if ($this->probe !== '') {
			$params['probe'] = $this->probe;
		}

		if ($this->presence !== 'all') {
			$params['presence'] = $this->presence;
		}

		if ($this->mapping !== 'all') {
			$params['mapping'] = $this->mapping;
		}

		if ($this->state !== 'all') {
			$params['state'] = $this->state;
		}

		if ($this->rows > 0) {
			$params['rows'] = $this->rows;
		}

		if ($includePage && $this->page > 1) {
			$params['page'] = $this->page;
		}

		return $params;
	}
}
