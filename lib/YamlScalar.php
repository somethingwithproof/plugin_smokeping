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
 * How a plain (unquoted) YAML scalar reads, shared by the reader and the
 * writer so they agree on which strings need quotes.
 *
 * YAML versions disagree about plain scalars: 1.1 reads yes, no, on, off as
 * booleans and 007 as octal; 1.2 does not. This subset reads only true, false,
 * null, ~, the empty value, and canonical decimal integers specially, and the
 * reader refuses every other plain scalar that any common version would read
 * as a non-string. The writer quotes those, so a file written here parses the
 * same under any YAML reader.
 */
final class YamlScalar {
	/** String constant. */
	public const STRING    = 'string';
	/** Nil constant. */
	public const NIL       = 'null';
	/** Bool constant. */
	public const BOOL      = 'bool';
	/** Int constant. */
	public const INT       = 'int';
	/** Ambiguous constant. */
	public const AMBIGUOUS = 'ambiguous';

	/**
	 * @return array{0: string, 1: null|bool|int|string}
	 */
	public static function classify(string $text): array {
		if ($text === '' || $text === '~' || in_array($text, ['null', 'Null', 'NULL'], true)) {
			return [self::NIL, null];
		}

		if (in_array($text, ['true', 'True', 'TRUE'], true)) {
			return [self::BOOL, true];
		}

		if (in_array($text, ['false', 'False', 'FALSE'], true)) {
			return [self::BOOL, false];
		}

		if (preg_match('/^(0|-?[1-9][0-9]{0,17})$/D', $text) === 1) {
			return [self::INT, (int) $text];
		}

		if (
			preg_match('/^(y|n|yes|no|on|off)$/iD', $text) === 1
			|| preg_match('/^[-+]?(\.[0-9]+|[0-9]+(\.[0-9]*)?)([eE][-+]?[0-9]+)?$/D', $text) === 1
			|| preg_match('/^[-+]?0[xXoObB]/', $text) === 1
			|| preg_match('/^[-+]?\.(inf|nan)$/iD', $text) === 1
			|| preg_match('/^[-+]?[0-9][0-9_]*(:[0-5]?[0-9])+(\.[0-9]*)?$/D', $text) === 1
			|| preg_match('/^[0-9]{4}-[0-9]{1,2}-[0-9]{1,2}/', $text) === 1
			|| $text === '='
			|| $text === '<<'
		) {
			return [self::AMBIGUOUS, $text];
		}

		return [self::STRING, $text];
	}

	/**
	 * Whether the writer may emit the string without quotes. Stricter than the
	 * reader needs: any colon or hash, an indicator first character, or edge
	 * space sends the string to quotes.
	 */
	public static function plainSafe(string $text): bool {
		if ($text === '' || self::classify($text)[0] !== self::STRING) {
			return false;
		}

		if (preg_match('/^[^\s\-?:,\[\]{}#&*!|>\'"%@`~=<]/u', $text) !== 1 || preg_match('/[\s]$/u', $text) === 1) {
			return false;
		}

		return strpbrk($text, ':#') === false && preg_match('/[\x00-\x1F\x7F]|\x{85}|\x{2028}|\x{2029}|\x{FEFF}/u', $text) !== 1;
	}
}
