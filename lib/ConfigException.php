<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Thomas Vincent

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
