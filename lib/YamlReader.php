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
 * Reader for a strict subset of YAML: block maps, block lists, plain,
 * single-quoted and double-quoted scalars, comments, one optional leading
 * "---", and "|" or "|-" literal blocks.
 *
 * Everything else is refused with a line-numbered error rather than guessed
 * at: anchors, aliases, tags, flow collections ([..] and {..}), folded blocks,
 * multi-line scalars, multiple documents, directives, merge keys (<<), complex
 * keys (?), duplicate keys, tabs as indentation, and plain scalars that
 * other YAML versions would read as something other than a string (see
 * YamlScalar). Input size and nesting depth are bounded.
 *
 * Plain scalars read as null, bool, int, or string; quoted scalars are always
 * strings. The reader runs no code and loads nothing: it is a function of the
 * text.
 */
final class YamlReader {
	public const MAX_BYTES = 8388608;
	public const MAX_DEPTH = 8;
	public const MAX_LINE  = 16384;

	/** @var array<int, string> */
	private array $raw = [];
	private int $pos = 0;
	/** @var array<string, int> */
	private array $lineMap = [];

	private function __construct(private readonly int $maxDepth) {
	}

	public static function parse(string $text, int $maxBytes = self::MAX_BYTES, int $maxDepth = self::MAX_DEPTH): ConfigTree {
		if (strlen($text) > $maxBytes) {
			throw new ConfigException(sprintf('The file is larger than the %d byte limit', $maxBytes));
		}

		if (str_starts_with($text, "\xEF\xBB\xBF")) {
			$text = substr($text, 3);
		}

		if (preg_match('//u', $text) !== 1) {
			throw new ConfigException('The file is not valid UTF-8');
		}

		$reader = new self($maxDepth);

		return $reader->run($text);
	}

	private function run(string $text): ConfigTree {
		$text = str_replace("\r\n", "\n", $text);

		if (str_contains($text, "\r")) {
			throw new ConfigException('A carriage return outside a line ending is not allowed');
		}

		$this->raw = explode("\n", $text);

		foreach ($this->raw as $i => $line) {
			if (strlen($line) > self::MAX_LINE) {
				throw new ConfigException(sprintf('The line is longer than %d bytes', self::MAX_LINE), $i + 1);
			}

			if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]|\x{85}|\x{2028}|\x{2029}|\x{FEFF}/u', $line) === 1) {
				throw new ConfigException('A control character is not allowed; write it as an escape in a double-quoted scalar', $i + 1);
			}
		}

		$first = $this->peek(true);

		if ($first !== null && $first[2] === '---') {
			$this->pos++;
		}

		$cur = $this->peek();

		if ($cur === null) {
			return new ConfigTree([], []);
		}

		$data = $this->block($cur[1], '', 1);

		$extra = $this->peek();

		if ($extra !== null) {
			throw new ConfigException('Unexpected content after the end of the document', $extra[0]);
		}

		if (!is_array($data)) {
			throw new ConfigException('The document must be a map or a list', $cur[0]);
		}

		return new ConfigTree($data, $this->lineMap);
	}

	/**
	 * The next line that is neither blank nor a comment, as
	 * [line number, indent, text without trailing spaces], without consuming it.
	 *
	 * @return array{0: int, 1: int, 2: string}|null
	 */
	private function peek(bool $allowMarker = false): ?array {
		while ($this->pos < count($this->raw)) {
			$line = rtrim($this->raw[$this->pos], ' ');
			$trim = ltrim($line, ' ');

			if (trim($trim, "\t") === '' || $trim[0] === '#') {
				$this->pos++;

				continue;
			}

			$indent = strlen($line) - strlen($trim);
			$no     = $this->pos + 1;

			if ($trim[0] === "\t") {
				throw new ConfigException('A tab cannot be used for indentation', $no);
			}

			if ($indent === 0 && preg_match('/^(---|\.\.\.)(\s|$)/', $trim) === 1 && (!$allowMarker || $trim !== '---')) {
				throw new ConfigException('Only one document is supported; a document marker was found', $no);
			}

			if ($indent === 0 && $trim[0] === '%') {
				throw new ConfigException('Directives are not supported', $no);
			}

			return [$no, $indent, $trim];
		}

		return null;
	}

	private function block(int $indent, string $path, int $depth): mixed {
		$cur = $this->peek();

		if ($cur === null) {
			return null;
		}

		return self::isItem($cur[2]) ? $this->sequence($indent, $path, $depth) : $this->map($indent, $path, $depth);
	}

	private function guardDepth(int $depth): void {
		if ($depth > $this->maxDepth) {
			throw new ConfigException(sprintf('Nesting is deeper than %d levels', $this->maxDepth), $this->pos + 1);
		}
	}

	private static function isItem(string $text): bool {
		return $text === '-' || str_starts_with($text, '- ');
	}

	/**
	 * @return array<array-key, mixed>
	 */
	private function map(int $indent, string $path, int $depth): array {
		$this->guardDepth($depth);

		$result = [];
		$seen   = [];

		while (($cur = $this->peek()) !== null) {
			[$no, $ind, $text] = $cur;

			if ($ind < $indent) {
				break;
			}

			if ($ind > $indent) {
				throw new ConfigException('Unexpected indentation', $no);
			}

			if (self::isItem($text)) {
				throw new ConfigException('A list item is not allowed where a key is expected', $no);
			}

			if ($text === '?' || str_starts_with($text, '? ')) {
				throw new ConfigException('Complex keys are not supported', $no);
			}

			$split = $this->splitKey($text, $no);

			if ($split === null) {
				throw new ConfigException('Expected "key: value"', $no);
			}

			[$key, $rest] = $split;

			if (isset($seen[$key])) {
				throw new ConfigException(sprintf('Duplicate key "%s" (first on line %d)', $key, $seen[$key]), $no);
			}

			$seen[$key] = $no;
			$sub        = $path === '' ? $key : $path . '/' . $key;

			$this->lineMap[$sub] = $no;
			$this->pos++;

			$result[$key] = $this->value($rest, $no, $indent, $sub, $depth, true);
		}

		return $result;
	}

	/**
	 * @return list<mixed>
	 */
	private function sequence(int $indent, string $path, int $depth): array {
		$this->guardDepth($depth);

		$result = [];

		while (($cur = $this->peek()) !== null) {
			[$no, $ind, $text] = $cur;

			if ($ind < $indent) {
				break;
			}

			if ($ind > $indent) {
				throw new ConfigException('Unexpected indentation', $no);
			}

			if (!self::isItem($text)) {
				break;
			}

			$after = ltrim(substr($text, 1), ' ');
			$col   = $indent + strlen($text) - strlen($after);
			$sub   = $path . '/' . count($result);

			$this->lineMap[$sub] = $no;

			if ($after !== '' && $after[0] !== '#' && self::isItem($after)) {
				throw new ConfigException('A list inside a list item on one line is not supported', $no);
			}

			if ($after !== '' && $after[0] !== '#' && $this->splitKey($after, $no) !== null) {
				// "- key: value": the item is a map whose keys align with "key".
				$this->raw[$no - 1] = str_repeat(' ', $col) . $after;
				$result[]           = $this->map($col, $sub, $depth + 1);

				continue;
			}

			$this->pos++;

			$result[] = $this->value($after, $no, $indent, $sub, $depth, false);
		}

		return $result;
	}

	/**
	 * The value after "key:" or "-". $rest is the text on the same line.
	 */
	private function value(string $rest, int $no, int $indent, string $path, int $depth, bool $inMap): mixed {
		if ($rest === '' || $rest[0] === '#') {
			$next = $this->peek();

			if ($next === null) {
				return null;
			}

			if ($next[1] > $indent) {
				return $this->block($next[1], $path, $depth + 1);
			}

			if ($inMap && $next[1] === $indent && self::isItem($next[2])) {
				return $this->sequence($indent, $path, $depth + 1);
			}

			return null;
		}

		$first = $rest[0];

		if ($first === '|') {
			return $this->literal($rest, $no, $indent);
		}

		$refusal = match (true) {
			$first === '>' => 'Folded blocks (>) are not supported',
			$first === '&' => 'Anchors are not supported',
			$first === '*' => 'Aliases are not supported',
			$first === '!' => 'Tags are not supported',
			$first === '[' || $first === '{' => 'Flow collections ([...] and {...}) are not supported; use a block list or map',
			$first === '%' || $first === '@' || $first === '`' => 'A plain scalar cannot start with "' . $first . '"; quote it',
			self::isItem($rest) => 'A list cannot start on the same line as its key',
			$first === '?' && (strlen($rest) === 1 || $rest[1] === ' ') => 'Complex keys are not supported',
			default => null,
		};

		if ($refusal !== null) {
			throw new ConfigException($refusal, $no);
		}

		return $this->scalar($rest, $no);
	}

	/**
	 * @return array{0: string, 1: string}|null key and the text after the colon
	 */
	private function splitKey(string $text, int $no): ?array {
		$first = $text[0];

		if ($first === '"' || $first === "'") {
			[$key, $end] = $this->quoted($text, 0, $no);
			$tail        = ltrim(substr($text, $end), ' ');

			if ($tail === '' || $tail[0] !== ':' || (strlen($tail) > 1 && $tail[1] !== ' ')) {
				return null;
			}

			return [$key, ltrim(substr($tail, 1), ' ')];
		}

		$head = $text;

		if (preg_match('/\s#/', $text, $m, PREG_OFFSET_CAPTURE) === 1) {
			$head = substr($text, 0, (int) $m[0][1]);
		}

		if (preg_match('/:(\s|$)/', $head, $m, PREG_OFFSET_CAPTURE) !== 1) {
			return null;
		}

		$at  = (int) $m[0][1];
		$key = rtrim(substr($head, 0, $at));

		if ($key === '') {
			throw new ConfigException('An empty key is not allowed', $no);
		}

		$refusal = match (true) {
			$key[0] === '&' => 'Anchors are not supported',
			$key[0] === '*' => 'Aliases are not supported',
			$key[0] === '!' => 'Tags are not supported',
			$key[0] === '[' || $key[0] === '{' => 'Flow collections are not supported',
			$key[0] === '?' => 'Complex keys are not supported',
			$key === '<<' => 'Merge keys (<<) are not supported',
			str_contains('%@`|>#', $key[0]) => 'A plain key cannot start with "' . $key[0] . '"; quote it',
			default => null,
		};

		if ($refusal !== null) {
			throw new ConfigException($refusal, $no);
		}

		[$type] = YamlScalar::classify($key);

		if ($type === YamlScalar::AMBIGUOUS || $type === YamlScalar::BOOL || $type === YamlScalar::NIL) {
			throw new ConfigException(sprintf('The key "%s" reads differently in other YAML versions; quote it', $key), $no);
		}

		return [$key, ltrim(substr($text, $at + 1), ' ')];
	}

	private function scalar(string $text, int $no): null|bool|int|string {
		if ($text[0] === '"' || $text[0] === "'") {
			[$value, $end] = $this->quoted($text, 0, $no);
			$tail          = substr($text, $end);

			if (trim($tail, ' ') !== '' && preg_match('/^\s+#/', $tail) !== 1) {
				throw new ConfigException('Unexpected text after a quoted scalar', $no);
			}

			return $value;
		}

		if (preg_match('/\s#/', $text, $m, PREG_OFFSET_CAPTURE) === 1) {
			$text = rtrim(substr($text, 0, (int) $m[0][1]));
		}

		if (preg_match('/:(\s|$)/', $text) === 1) {
			throw new ConfigException('A ": " inside a plain scalar is ambiguous; quote the value', $no);
		}

		[$type, $value] = YamlScalar::classify($text);

		if ($type === YamlScalar::AMBIGUOUS) {
			throw new ConfigException(sprintf('The plain scalar "%s" reads differently in other YAML versions; quote it', $text), $no);
		}

		return $value;
	}

	/**
	 * @return array{0: string, 1: int} the string and the offset after the closing quote
	 */
	private function quoted(string $text, int $start, int $no): array {
		$quote = $text[$start];
		$out   = '';
		$len   = strlen($text);

		for ($i = $start + 1; $i < $len; $i++) {
			$c = $text[$i];

			if ($quote === "'") {
				if ($c !== "'") {
					$out .= $c;

					continue;
				}

				if ($i + 1 < $len && $text[$i + 1] === "'") {
					$out .= "'";
					$i++;

					continue;
				}

				return [$out, $i + 1];
			}

			if ($c === '"') {
				return [$out, $i + 1];
			}

			if ($c !== '\\') {
				$out .= $c;

				continue;
			}

			$i++;

			if ($i >= $len) {
				break;
			}

			$e = $text[$i];

			$simple = ['\\' => '\\', '"' => '"', '/' => '/', 'n' => "\n", 't' => "\t", 'r' => "\r", '0' => "\0", 'b' => "\x08", 'f' => "\x0C", 'e' => "\x1B"];

			if (isset($simple[$e])) {
				$out .= $simple[$e];

				continue;
			}

			$digits = $e === 'x' ? 2 : ($e === 'u' ? 4 : 0);
			$hex    = $digits > 0 ? substr($text, $i + 1, $digits) : '';

			if ($digits === 0 || strlen($hex) !== $digits || preg_match('/^[0-9A-Fa-f]+$/', $hex) !== 1) {
				throw new ConfigException(sprintf('Unknown escape "\\%s" in a double-quoted scalar', $e), $no);
			}

			$code = (int) hexdec($hex);

			if ($code >= 0xD800 && $code <= 0xDFFF) {
				throw new ConfigException('A surrogate code point is not allowed', $no);
			}

			// Encoded by hand so the reader needs no mbstring.
			$out .= self::utf8($code);
			$i   += $digits;
		}

		throw new ConfigException('Unterminated quoted scalar (a quoted scalar must close on the line it starts)', $no);
	}

	private static function utf8(int $code): string {
		return match (true) {
			$code < 0x80  => chr($code),
			$code < 0x800 => chr(0xC0 | ($code >> 6)) . chr(0x80 | ($code & 0x3F)),
			default       => chr(0xE0 | ($code >> 12)) . chr(0x80 | (($code >> 6) & 0x3F)) . chr(0x80 | ($code & 0x3F)),
		};
	}

	/**
	 * A "|" or "|-" literal block. The current raw line is the one after the
	 * header, because the caller already moved past it.
	 */
	private function literal(string $header, int $no, int $parentIndent): string {
		$rest = $header;

		if (preg_match('/\s#/', $rest, $m, PREG_OFFSET_CAPTURE) === 1) {
			$rest = rtrim(substr($rest, 0, (int) $m[0][1]));
		}

		if ($rest !== '|' && $rest !== '|-') {
			throw new ConfigException('Only the "|" and "|-" literal block styles are supported', $no);
		}

		$lines       = [];
		$blockIndent = null;

		while ($this->pos < count($this->raw)) {
			$line = $this->raw[$this->pos];

			if (trim($line, " \t") === '') {
				$lines[] = '';
				$this->pos++;

				continue;
			}

			$indent = strlen($line) - strlen(ltrim($line, ' '));

			if ($indent <= $parentIndent) {
				break;
			}

			$blockIndent ??= $indent;

			if ($indent < $blockIndent) {
				throw new ConfigException('Inconsistent indentation inside a literal block', $this->pos + 1);
			}

			$lines[] = substr($line, $blockIndent);
			$this->pos++;
		}

		while ($lines !== [] && end($lines) === '') {
			array_pop($lines);
		}

		if ($lines === []) {
			return '';
		}

		return implode("\n", $lines) . ($rest === '|' ? "\n" : '');
	}
}
