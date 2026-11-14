<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Thomas Vincent

namespace Somethingwithproof\Smokeping;

/**
 * One section of a parsed SmokePing configuration: its variables, including
 * inherited and default values the way Config::Grammar fills them in, and
 * its subsections in file order.
 *
 * The parser builds and mutates these while it reads; consumers only read.
 */
final class SmokepingSection {
	/** @var array<string, string> */
	public array $vars = [];
	/** @var array<string, true> variables copied from the parent and not set here */
	public array $inherited = [];
	/** @var array<string, SmokepingSection> */
	public array $children = [];
	/** @var list<string> variables set explicitly in this section, in order */
	public array $varlist = [];
	/** @var list<string> */
	public array $mandatory = [];
	/** @var list<list<string>> rows of an unkeyed table */
	public array $table = [];
	/** Probe whose target variables apply here (target sections), or the probe module (probe sections). */
	public ?string $probe = null;
	/** @var list<string>|null target variables that become mandatory once host is set */
	public ?array $hostMandatory = null;
	public bool $orderedChild = false;

	public function __construct(
		public readonly string $name,
		public readonly string $kind,
		public readonly string $path,
		public readonly int $depth,
	) {
	}

	public function value(string $name): ?string {
		return $this->vars[$name] ?? null;
	}

	public function child(string $name): ?self {
		return $this->children[$name] ?? null;
	}

	/**
	 * Is the name taken in this section, by a variable, a subsection, or the
	 * bookkeeping keys Config::Grammar stores in the same hash?
	 */
	public function hasKey(string $name): bool {
		if (isset($this->vars[$name]) || isset($this->children[$name])) {
			return true;
		}

		if ($this->depth === 0) {
			return $name === '_order_count' && $this->orderedChild;
		}

		return match ($name) {
			'_inherited', '_is_section', '_grammar' => true,
			'_varlist'     => $this->kind === SmokepingGrammar::TARGET,
			'_order'       => $this->kind === SmokepingGrammar::TARGET || $this->kind === SmokepingGrammar::TARGETS,
			'_order_count' => $this->orderedChild,
			default        => false,
		};
	}
}
