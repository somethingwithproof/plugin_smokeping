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

/**
 * Scan a PHP source file with token_get_all() and return every symbol
 * declaration (class, interface, trait, enum, const, property, method,
 * function) that lacks a preceding PHPDoc block.
 *
 * @param string $path Absolute path to a PHP file.
 * @return list<string> Missing DocBlock descriptors formatted as "line:kind:name".
 */
function smokeping_missing_phpdocs(string $path): array {
	$code   = (string) file_get_contents($path);
	$tokens = token_get_all($code);
	$count  = count($tokens);

	$braceDepth = 0;
	$parenDepth = 0;
	/** @var list<int> $classStack */
	$classStack = [];
	/** @var list<string> $missing */
	$missing = [];

	for ($i = 0; $i < $count; $i++) {
		$token = $tokens[$i];

		if (is_string($token)) {
			if ($token === '{') {
				$braceDepth++;
			} elseif ($token === '}') {
				$braceDepth--;

				if ($classStack !== [] && end($classStack) === $braceDepth) {
					array_pop($classStack);
				}
			} elseif ($token === '(') {
				$parenDepth++;
			} elseif ($token === ')') {
				$parenDepth--;
			}

			continue;
		}

		$id = $token[0];

		if ($id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
			$braceDepth++;

			continue;
		}

		$isDecl = false;
		$kind   = '';
		$name   = '';

		if ($id === T_CLASS || $id === T_INTERFACE || $id === T_TRAIT || $id === T_ENUM) {
			$prev = $i - 1;

			while ($prev >= 0 && is_array($tokens[$prev]) && in_array($tokens[$prev][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
				$prev--;
			}

			if ($prev >= 0 && is_array($tokens[$prev]) && in_array($tokens[$prev][0], [T_DOUBLE_COLON, T_NEW], true)) {
				continue;
			}

			$next = $i + 1;

			while ($next < $count && is_array($tokens[$next]) && $tokens[$next][0] === T_WHITESPACE) {
				$next++;
			}

			if ($next < $count && is_array($tokens[$next]) && $tokens[$next][0] === T_STRING) {
				$isDecl       = true;
				$kind         = strtolower($token[1]);
				$name         = $tokens[$next][1];
				$classStack[] = $braceDepth;
			}
		} elseif ($id === T_FUNCTION) {
			$next = $i + 1;

			while ($next < $count && is_array($tokens[$next]) && $tokens[$next][0] === T_WHITESPACE) {
				$next++;
			}

			if ($next < $count && $tokens[$next] === '&') {
				$next++;

				while ($next < $count && is_array($tokens[$next]) && $tokens[$next][0] === T_WHITESPACE) {
					$next++;
				}
			}

			if ($next < $count && is_array($tokens[$next]) && $tokens[$next][0] === T_STRING) {
				$isDecl = true;
				$kind   = $classStack !== [] ? 'method' : 'function';
				$name   = $tokens[$next][1];
			}
		} elseif ($id === T_CONST && $parenDepth === 0) {
			$next = $i + 1;

			while ($next < $count && is_array($tokens[$next]) && $tokens[$next][0] === T_WHITESPACE) {
				$next++;
			}

			if ($next < $count && is_array($tokens[$next]) && $tokens[$next][0] === T_STRING) {
				$isDecl = true;
				$kind   = 'const';
				$name   = $tokens[$next][1];
			}
		} elseif ($id === T_VARIABLE && $parenDepth === 0 && $classStack !== [] && $braceDepth === end($classStack) + 1) {
			$j      = $i - 1;
			$hasMod = false;

			while ($j >= 0) {
				$pt = $tokens[$j];

				if ($pt === ';' || $pt === '{' || $pt === '}' || $pt === ',' || $pt === '(' || $pt === '=') {
					break;
				}

				if (is_array($pt) && in_array($pt[0], [T_PUBLIC, T_PROTECTED, T_PRIVATE, T_VAR, T_STATIC, T_READONLY], true)) {
					$hasMod = true;
				}

				$j--;
			}

			if ($hasMod) {
				$isDecl = true;
				$kind   = 'property';
				$name   = $token[1];
			}
		}

		if (!$isDecl) {
			continue;
		}

		$hasDoc    = false;
		$j         = $i - 1;
		$attrDepth = 0;

		while ($j >= 0) {
			$pt = $tokens[$j];

			if ($pt === ']') {
				$attrDepth++;
				$j--;

				continue;
			}

			if ($attrDepth > 0) {
				if (is_array($pt) && $pt[0] === T_ATTRIBUTE) {
					$attrDepth--;
				} elseif ($pt === '[') {
					$attrDepth--;
				}

				$j--;

				continue;
			}

			if (is_array($pt)) {
				if ($pt[0] === T_DOC_COMMENT) {
					$hasDoc = true;

					break;
				}

				if (in_array($pt[0], [
					T_WHITESPACE,
					T_COMMENT,
					T_PUBLIC,
					T_PROTECTED,
					T_PRIVATE,
					T_STATIC,
					T_FINAL,
					T_ABSTRACT,
					T_READONLY,
					T_VAR,
					T_STRING,
					T_NAME_QUALIFIED,
					T_NAME_FULLY_QUALIFIED,
					T_ARRAY,
					T_CALLABLE,
					T_NS_SEPARATOR,
				], true)) {
					$j--;

					continue;
				}

				break;
			}

			if (in_array($pt, ['?', '|', '&'], true)) {
				$j--;

				continue;
			}

			break;
		}

		if (!$hasDoc) {
			$missing[] = sprintf('%d:%s:%s', $token[2], $kind, $name);
		}
	}

	return $missing;
}

/**
 * List all production PHP source files under plugin_smokeping.
 *
 * @return list<string> Relative paths from repository root.
 */
function smokeping_production_php_files(): array {
	$root     = dirname(__DIR__, 2);
	$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
	$files    = [];

	foreach ($iterator as $file) {
		/** @var SplFileInfo $file */
		$relative = substr($file->getPathname(), strlen($root) + 1);

		if (
			$file->getExtension() === 'php'
			&& !str_starts_with($relative, 'tests/')
			&& !str_starts_with($relative, 'tools/')
			&& !str_starts_with($relative, '.git/')
			&& !str_starts_with($relative, 'vendor/')
		) {
			$files[] = $relative;
		}
	}

	sort($files);

	return $files;
}

it('enforces 100% PHPDoc coverage across every production PHP symbol', function () {
	$root       = dirname(__DIR__, 2);
	$violations = [];

	foreach (smokeping_production_php_files() as $relative) {
		foreach (smokeping_missing_phpdocs($root . '/' . $relative) as $item) {
			$violations[] = $relative . ':' . $item;
		}
	}

	expect($violations)->toBe([], "Missing PHPDoc blocks:\n" . implode("\n", $violations));
});
