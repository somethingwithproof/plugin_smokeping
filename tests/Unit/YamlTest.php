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

use Somethingwithproof\Smokeping\ConfigException;
use Somethingwithproof\Smokeping\YamlReader;
use Somethingwithproof\Smokeping\YamlScalar;
use Somethingwithproof\Smokeping\YamlWriter;

function smokeping_yaml(string $text): array {
	return YamlReader::parse($text)->data;
}

function smokeping_yaml_error(string $text): string {
	try {
		YamlReader::parse($text);
	} catch (ConfigException $e) {
		return $e->getMessage();
	}

	return '';
}

it('reads block maps, lists, scalars, and comments', function () {
	$text = <<<'YAML'
	# a comment
	---
	name: plain text   # trailing comment
	count: 12
	negative: -3
	flag: true
	off_flag: false
	nothing: null
	tilde: ~
	empty:
	single: 'it''s'
	double: "tab\there \u00e9 \x41"
	"quoted key": 1
	list:
	  - a
	  - 'b'
	  - 3
	same_indent_list:
	- x
	- z
	items:
	  - id: 1
	    tags:
	      - t1
	  - id: 2
	literal: |
	  line one
	  line two
	stripped: |-
	  only
	last: end
	YAML;

	$data = smokeping_yaml(preg_replace('/^\t/m', '', $text));

	expect($data['name'])->toBe('plain text')
		->and($data['count'])->toBe(12)
		->and($data['negative'])->toBe(-3)
		->and($data['flag'])->toBeTrue()
		->and($data['off_flag'])->toBeFalse()
		->and($data['nothing'])->toBeNull()
		->and($data['tilde'])->toBeNull()
		->and($data['empty'])->toBeNull()
		->and($data['single'])->toBe("it's")
		->and($data['double'])->toBe("tab\there \u{e9} A")
		->and($data['quoted key'])->toBe(1)
		->and($data['list'])->toBe(['a', 'b', 3])
		->and($data['same_indent_list'])->toBe(["x", "z"])
		->and($data['items'])->toBe([['id' => 1, 'tags' => ['t1']], ['id' => 2]])
		->and($data['literal'])->toBe("line one\nline two\n")
		->and($data['stripped'])->toBe('only')
		->and($data['last'])->toBe('end');
});

it('records the line of every key and item', function () {
	$tree = YamlReader::parse("a: 1\nlist:\n  - x\n  - k: v\nb: 2\n");

	expect($tree->lines)->toBe(['a' => 1, 'list' => 2, 'list/0' => 3, 'list/1' => 4, 'list/1/k' => 4, 'b' => 5]);
});

it('refuses unsupported constructs with the line number', function (string $text, int $line, string $fragment) {
	$message = smokeping_yaml_error($text);

	expect($message)->toStartWith("line $line: ")->and($message)->toContain($fragment);
})->with([
	'anchor'          => ["a: &x 1\n", 1, 'Anchors'],
	'alias'           => ["a: 1\nb: *x\n", 2, 'Aliases'],
	'tag'             => ["a: !!str 1\n", 1, 'Tags'],
	'flow list'       => ["a: [1, 2]\n", 1, 'Flow'],
	'flow map'        => ["a:\n  b: {x: 1}\n", 2, 'Flow'],
	'folded'          => ["a: >\n  text\n", 1, 'Folded'],
	'keep chomp'      => ["a: |+\n  text\n", 1, 'literal block styles'],
	'second document' => ["a: 1\n---\nb: 2\n", 2, 'one document'],
	'document end'    => ["a: 1\n...\n", 2, 'one document'],
	'directive'       => ["%YAML 1.2\na: 1\n", 1, 'Directives'],
	'merge key'       => ["a: 1\n<<: x\n", 2, 'Merge'],
	'complex key'     => ["? a\n: b\n", 1, 'Complex'],
	'duplicate key'   => ["a: 1\nb: 2\na: 3\n", 3, 'Duplicate key "a" (first on line 1)'],
	'duplicate nested' => ["m:\n  k: 1\n  k: 2\n", 3, 'Duplicate key "k"'],
	'tab indent'      => ["a:\n\tb: 1\n", 2, 'tab'],
	'bad indent'      => ["a: 1\n  b: 2\n", 2, 'indentation'],
	'multi-line plain' => ["a: one\n  two\n", 2, 'indentation'],
	'unterminated dq' => ["a: \"open\n", 1, 'Unterminated'],
	'unterminated sq' => ["a: 'open\n", 1, 'Unterminated'],
	'trailing junk'   => ["a: 'x' y\n", 1, 'after a quoted'],
	'bad escape'      => ["a: \"\\q\"\n", 1, 'escape'],
	'surrogate'       => ["a: \"\\uD800\"\n", 1, 'surrogate'],
	'colon in plain'  => ["a: b: c\n", 1, 'ambiguous'],
	'bare word'       => ["just text\n", 1, 'Expected "key: value"'],
	'yes'             => ["a: yes\n", 1, 'quote it'],
	'off'             => ["a: Off\n", 1, 'quote it'],
	'octal'           => ["a: 0755\n", 1, 'quote it'],
	'float'           => ["a: 1.5\n", 1, 'quote it'],
	'hex'             => ["a: 0x1F\n", 1, 'quote it'],
	'date'            => ["a: 2026-01-02\n", 1, 'quote it'],
	'sexagesimal'     => ["a: 1:30\n", 1, 'quote it'],
	'at sign'         => ["a: @x\n", 1, 'quote it'],
	'control char'    => ["a: \x01\n", 1, 'control character'],
	'nested list'     => ["a:\n  - - x\n", 2, 'inside a list item'],
	'list on key line' => ["a: - x\n", 1, 'same line'],
	'bad key'         => ["yes: 1\n", 1, 'quote it'],
]);

it('refuses nesting beyond the limit and oversize input', function () {
	$deep = '';

	for ($i = 0; $i < 12; $i++) {
		$deep .= str_repeat('  ', $i) . "k$i:\n";
	}

	expect(smokeping_yaml_error($deep . str_repeat('  ', 12) . "leaf: 1\n"))->toContain('Nesting is deeper than 8 levels');
	expect(fn () => YamlReader::parse('a: 1', 3))->toThrow(ConfigException::class, 'larger than');
	expect(fn () => YamlReader::parse("a: \xFF\n"))->toThrow(ConfigException::class, 'UTF-8');
	expect(fn () => YamlReader::parse("a: 1\rb: 2\n"))->toThrow(ConfigException::class, 'carriage return');
	expect(fn () => YamlReader::parse('a: ' . str_repeat('x', YamlReader::MAX_LINE) . "\n"))->toThrow(ConfigException::class, 'longer than');
});

it('reads CRLF line endings, a BOM, and an empty document', function () {
	expect(smokeping_yaml("\xEF\xBB\xBFa: 1\r\nb:\r\n  - x\r\n"))->toBe(['a' => 1, 'b' => ['x']])
		->and(smokeping_yaml("# only a comment\n"))->toBe([]);
});

/**
 * Strings a reader could misparse; each must survive a write and a read.
 *
 * @return list<string>
 */
function smokeping_awkward_strings(): array {
	return [
		'yes', 'no', 'on', 'off', 'Yes', 'NO', 'On', 'OFF', 'y', 'n', 'Y', 'N', 'true', 'false', 'True', 'FALSE',
		'null', 'Null', '~', '', ' ', '007', '0', '00', '-0', '0755', '12', '-12', '+12', '1.5', '1e3', '.5', '0x1F', '0o17', '0b11',
		'.inf', '-.INF', '.nan', '1:30', '12:30:45', '2026-01-02', '2026-01-02T10:00:00Z', '=', '<<',
		' leading', 'trailing ', "\ttab", "trail\t", 'a: b', 'a:b', 'a:', ':a', 'key: ', 'x #y', '#x', 'a#b', '- dash', '-', '--', '---', '...',
		'? q', '?x', '*star', '&amp', '!tag', '|pipe', '>fold', '%pct', '@at', '`tick', "'single", '"double', 'it\'s', 'say "hi"',
		'[x]', '{x}', 'a,b', 'back\\slash', "multi\nline", "cr\rlf", "nul\0byte", "bell\x07", "del\x7F", "\u{85}nel", "\u{2028}ls", "\u{FEFF}bom",
		'é', '日本語', '😀', 'plain', '/etc/smokeping/config', 'Europe/Paris', 'a b c', '10.0.0.1:8080', 'x.y-z_w',
	];
}

it('quotes every scalar a reader could misparse and reads it back unchanged', function (string $value) {
	$text = YamlWriter::write(['v' => $value, 'list' => [$value], 'map' => [['k' => $value]]]);
	$back = smokeping_yaml($text);

	expect($back)->toBe(['v' => $value, 'list' => [$value], 'map' => [['k' => $value]]], $text);
})->with(array_map(fn (string $s) => [$s], smokeping_awkward_strings()));

it('writes plain text where it is safe and quotes where it is not', function () {
	$text = YamlWriter::write(['a' => 'plain', 'b' => 'yes', 'c' => 'x: y', 'd' => ' pad', 'e' => '007', 'f' => 'null', 'g' => true, 'h' => 5, 'i' => "two\nlines"]);

	expect($text)->toBe("a: plain\nb: 'yes'\nc: 'x: y'\nd: ' pad'\ne: '007'\nf: 'null'\ng: true\nh: 5\ni: \"two\\nlines\"\n");
});

it('writes nothing it cannot read back', function () {
	expect(fn () => YamlWriter::write(['a' => []]))->toThrow(ConfigException::class, 'empty')
		->and(fn () => YamlWriter::write([]))->toThrow(ConfigException::class)
		->and(fn () => YamlWriter::write(['a' => 1.5]))->toThrow(ConfigException::class)
		->and(fn () => YamlWriter::write(['a' => "\xFF"]))->toThrow(ConfigException::class, 'UTF-8')
		->and(fn () => YamlWriter::write(['a' => [['x'], ['y']]]))->toThrow(ConfigException::class);
});

/**
 * A deterministic random tree of maps, lists, and scalars within the depth
 * limit, for round-trip checks.
 */
function smokeping_random_tree(int $depth): array {
	$pool  = smokeping_awkward_strings();
	$value = function () use ($pool) {
		return match (mt_rand(0, 5)) {
			0 => mt_rand(-1000, 1000),
			1 => (bool) mt_rand(0, 1),
			2 => $pool[mt_rand(0, count($pool) - 1)],
			default => 'w' . mt_rand(0, 99999),
		};
	};

	$count = mt_rand(1, 4);
	$data  = [];

	if (mt_rand(0, 2) === 0 && $depth > 1) {
		for ($i = 0; $i < $count; $i++) {
			$data[] = mt_rand(0, 3) === 0 ? smokeping_random_map($depth - 1, $value) : $value();
		}

		return $data;
	}

	return smokeping_random_map($depth, $value);
}

function smokeping_random_map(int $depth, callable $value): array {
	$map = [];

	for ($i = 0, $n = mt_rand(1, 4); $i < $n; $i++) {
		$key       = 'k' . mt_rand(0, 9) . $i;
		$map[$key] = $depth > 1 && mt_rand(0, 2) === 0 ? smokeping_random_tree($depth - 1) : $value();
	}

	return $map;
}

it('round-trips thousands of generated documents, deterministically by seed', function () {
	mt_srand(20261113);

	for ($i = 0; $i < 3000; $i++) {
		$tree = smokeping_random_tree(mt_rand(1, 6));
		$text = YamlWriter::write($tree);

		expect(smokeping_yaml($text))->toBe($tree, $text);
	}
});

it('fails only with a ConfigException on thousands of mutated and random inputs', function () {
	mt_srand(20261114);

	$seed = YamlWriter::write(['schema_version' => 1, 'settings' => ['smokeping_poller_id' => 1, 'smokeping_config_file' => '/etc/smokeping/config'], 'targets' => [['path' => 'Europe/Paris', 'poller_id' => 1, 'host' => 'a.example.net', 'slaves' => ['x', 'y'], 'present' => true]], 'mappings' => [['target' => 'Europe/Paris', 'device' => ['hostname' => 'h', 'description' => 'd: e']]]]);
	$bits = ['- ', ': ', ':', '#', '"', "'", '\\', '|', '>', '&', '*', '!', '[', ']', '{', '}', '?', '<<', '---', '...', "\t", '  ', "\n", "\n  ", "\r", "\0", "\xFF", '%', '@', '`', 'yes', 'null', '~', '0x1', '1e3', '\\u00', '\\x4'];
	$ok   = 0;
	$bad  = 0;

	for ($i = 0; $i < 4000; $i++) {
		$text = $seed;

		if ($i % 4 === 3) {
			// A random soup of fragments.
			$text = '';

			for ($j = 0, $n = mt_rand(1, 30); $j < $n; $j++) {
				$text .= $bits[mt_rand(0, count($bits) - 1)] . (mt_rand(0, 2) === 0 ? 'w' : '');
			}
		} else {
			for ($m = 0, $n = mt_rand(1, 4); $m < $n; $m++) {
				$at   = mt_rand(0, strlen($text));
				$text = match (mt_rand(0, 2)) {
					0 => substr($text, 0, $at) . $bits[mt_rand(0, count($bits) - 1)] . substr($text, $at),
					1 => substr($text, 0, $at) . substr($text, min(strlen($text), $at + mt_rand(1, 6))),
					default => substr($text, 0, $at),
				};
			}
		}

		try {
			YamlReader::parse($text);
			$ok++;
		} catch (ConfigException) {
			$bad++;
		}
	}

	// Both outcomes must occur, or the corpus is not exercising the reader.
	expect($ok)->toBeGreaterThan(100)->and($bad)->toBeGreaterThan(100);
});

it('classifies a scalar with a trailing newline as a plain string, not a number or ambiguous word', function (string $text) {
	expect(YamlScalar::classify($text)[0])->toBe(YamlScalar::STRING);
})->with(["12\n", "yes\n", "1.5\n", ".inf\n", "1:30\n"]);
