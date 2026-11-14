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
 * A configuration file that cannot be read, or whose content is refused.
 * A parser error already names its line in the message. A schema check
 * collects every problem it finds in $errors, one line each, so an operator
 * fixes the file once.
 */
final class ConfigException extends \RuntimeException {
	/**
	 * @param list<string> $errors
	 */
	public function __construct(string $message, public readonly ?int $sourceLine = null, public readonly array $errors = []) {
		parent::__construct($sourceLine === null ? $message : sprintf('line %d: %s', $sourceLine, $message));
	}

	/**
	 * @return list<string>
	 */
	public function lines(): array {
		return $this->errors === [] ? [$this->getMessage()] : $this->errors;
	}
}
