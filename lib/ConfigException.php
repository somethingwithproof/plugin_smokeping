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
