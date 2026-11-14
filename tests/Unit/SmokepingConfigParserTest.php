<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Thomas Vincent

use Somethingwithproof\Smokeping\Inventory;
use Somethingwithproof\Smokeping\SmokepingConfigError;
use Somethingwithproof\Smokeping\SmokepingConfigParser;
use Somethingwithproof\Smokeping\SmokepingConfigReader;
use Somethingwithproof\Smokeping\SmokepingInventory;

/**
 * The PHP reader of SmokePing's configuration format. tests/oracle/run.sh
 * compares it with SmokePing's own parser over generated and packaged
 * files; these tests pin the behaviour that comparison found, and the
 * limits SmokePing does not have.
 */

/**
 * A directory with the given files; returns its path.
 *
 * @param array<string, string> $files
 */
function sp_dir(array $files): string {
	$dir = sys_get_temp_dir() . '/sp-parser-' . bin2hex(random_bytes(6));
	mkdir($dir, 0700, true);

	foreach ($files as $name => $text) {
		if (!is_dir(dirname("$dir/$name"))) {
			mkdir(dirname("$dir/$name"), 0700, true);
		}
		file_put_contents("$dir/$name", $text);
	}

	return $dir;
}

/**
 * The sections every valid file needs, then the given Probes and Targets text.
 */
function sp_config(string $targets, string $probes = "+ FPing\nbinary = /usr/bin/fping\n", string $database = "step = 300\npings = 20\n", string $general = ''): string {
	return "*** General ***\nowner = o\ncontact = o@example.net\ncgiurl = http://localhost/\nimgcache = /c\nimgurl = c\ndatadir = /var/lib/smokeping\npiddir = /run\nsmokemail = /m\n$general\n"
		. "*** Database ***\n$database\n*** Presentation ***\ntemplate = /t\n+ overview\nwidth = 600\nheight = 50\n+ detail\nwidth = 600\nheight = 200\n\n"
		. "*** Probes ***\n$probes\n*** Targets ***\n$targets";
}

/**
 * @return array<string, mixed>
 */
function sp_parse(string $text): array {
	return SmokepingInventory::document((new SmokepingConfigParser())->parseString($text, 'test.conf'));
}

function sp_error(string $text): SmokepingConfigError {
	try {
		(new SmokepingConfigParser())->parseString($text, 'test.conf');
	} catch (SmokepingConfigError $e) {
		return $e;
	}

	throw new RuntimeException('the configuration was accepted');
}

/**
 * @return array<string, array<string, mixed>>
 */
function sp_targets(string $text): array {
	$out = [];

	foreach (sp_parse($text)['targets'] as $target) {
		$out[$target['path']] = $target;
	}

	return $out;
}

describe('SmokepingConfigParser', function () {
	it('reads the inventory fixture exactly as the Perl oracle printed it', function () {
		$expected = json_decode(str_replace(['@DIR@', '@VERSION@'], ['/fx', ''], (string) file_get_contents(dirname(__DIR__) . '/Fixtures/inventory.json')), true);
		$text     = str_replace('@DIR@', '/fx', (string) file_get_contents(dirname(__DIR__) . '/Fixtures/inventory.conf'));
		$actual   = sp_parse($text);

		ksort($expected);
		ksort($actual);

		expect($actual)->toEqual($expected);
	});

	it('inherits probe, slaves and nomasterpoll, and a fork probe\'s pings, down the tree', function () {
		$t = sp_targets(sp_config(
			"probe = DNS\nmenu = m\ntitle = t\npings = 7\nslaves = s1\n+ a\nnomasterpoll = yes\n++ b\nhost = 192.0.2.1\n+++ c\nhost = 192.0.2.2\npings = 9\n",
			"+ DNS\nbinary = /usr/bin/dig\n"
		));

		expect($t['a/b'])->toMatchArray(['probe' => 'DNS', 'pings' => 7, 'slaves' => ['s1'], 'nomasterpoll' => true])
			->and($t['a/b/c'])->toMatchArray(['pings' => 9, 'nomasterpoll' => true]);
	});

	it('inherits host for a probe whose target variables include it', function () {
		$t = sp_targets(sp_config(
			"probe = TraceroutePing\nmenu = m\ntitle = t\n+ a\nhost = 192.0.2.1\ndesthost = 192.0.2.9\n++ b\nmenu = child\n",
			"+ TraceroutePing\nbinary = /usr/bin/traceroute\n"
		));

		expect(array_keys($t))->toBe(['a', 'a/b'])
			->and($t['a/b']['host'])->toBe('192.0.2.1');
	});

	it('resolves step and pings through the probe instance, its probe, and the Database section', function () {
		$probes = "+ FPing\nbinary = /usr/bin/fping\nstep = 60\n++ Fast\npings = 5\n++ Slow\nstep = 600\n";
		$t      = sp_targets(sp_config("probe = Fast\nmenu = m\ntitle = t\n+ a\nhost = h1\n+ b\nprobe = Slow\nhost = h2\n", $probes));

		expect([$t['a']['step'], $t['a']['pings']])->toBe([60, 5])
			->and([$t['b']['step'], $t['b']['pings']])->toBe([600, 20]);

		$t = sp_targets(sp_config("probe = Fast\nmenu = m\ntitle = t\n+ a\nhost = h1\n", $probes, general: 'concurrentprobes = no'));

		expect($t['a']['step'])->toBe(300);
	});

	it('uses a probe\'s default pings', function () {
		$t = sp_targets(sp_config("probe = DismanPing\nmenu = m\ntitle = t\n+ a\nhost = h1\npinghost = 192.0.2.1\n", "+ DismanPing\n"));

		expect($t['a']['pings'])->toBe(15);
	});

	it('leaves out multi-host targets and lists children in byte order, not file order', function () {
		$t = sp_parse(sp_config("probe = FPing\nmenu = m\ntitle = t\n+ b\nhost = h\n+ B\nhost = h\n+ _x\nhost = h\n+ a1\nhost = /b /B\n+ 9\nhost = h\n"));

		expect(array_column($t['targets'], 'path'))->toBe(['9', 'B', '_x', 'b']);
	});

	it('trims lines, strips comments, joins continuation lines and reads CRLF files', function () {
		$t = sp_targets(str_replace("\n", "\r\n", sp_config("probe = FPing\nmenu = m\ntitle = t\n  + a   # a comment\n\ttitle = one \\\n   two # not a comment on a continued line\nhost = h1 # trailing\n")));

		expect($t['a']['title'])->toBe('one  two # not a comment on a continued line')
			->and($t['a']['host'])->toBe('h1');
	});

	it('accepts the section header spellings SmokePing accepts', function () {
		$text = str_replace(['*** Probes ***', '*** Targets ***'], ['***Probes***', '***   Targets'], sp_config("probe = FPing\nmenu = m\ntitle = t\n+ a\nhost = h\n"));

		expect(fn () => sp_parse($text))->toThrow(SmokepingConfigError::class);
		expect(sp_targets(str_replace('***   Targets', '***   Targets   ***', $text)))->toHaveKey('a');
	});
});

describe('SmokepingConfigParser errors', function () {
	it('names the file and line, as SmokePing does', function (string $targets, string $message) {
		$text = sp_config($targets);
		$e    = sp_error($text);
		$line = count(explode("\n", sp_config(''))) - 1 + 4;

		expect($e->source)->toBe('test.conf')
			->and($e->sourceLine)->toBe($line)
			->and($e->getMessage())->toBe("test.conf, line $line: $message");
	})->with([
		'unknown variable'   => ["probe = FPing\nmenu = m\ntitle = t\nhots = x\n", "unknown variable 'hots'"],
		'duplicate variable' => ["probe = FPing\nmenu = m\ntitle = t\nmenu = again\n", 'variable already defined'],
		'section name'       => ["probe = FPing\nmenu = m\ntitle = t\n+ bad.name\n", "unknown section 'bad.name'"],
		'nesting'            => ["probe = FPing\nmenu = m\ntitle = t\n++ deep\n", 'section nesting error'],
		'repeated section'   => ["probe = FPing\nmenu = m\n+ a\n+ a\n", 'section or variable already exists'],
	]);

	it('refuses a probe the Probes section does not define, and a probe set after the host', function () {
		expect(sp_error(sp_config("probe = Nope\n"))->reason)->toBe('probe Nope missing from the Probes section')
			->and(sp_error(sp_config("probe = FPing\nmenu = m\ntitle = t\n+ a\nhost = h\nprobe = FPing\n"))->reason)->toBe('probe must be defined before the host or any probe variables')
			->and(sp_error(sp_config("probe = FPing\nmenu = m\ntitle = t\n+ a\npings = 5\n"))->reason)->toBe("unknown variable 'pings'");
	});

	it('checks values with SmokePing\'s patterns and checks', function () {
		expect(sp_error(sp_config("probe = FPing\nmenu = m\ntitle = t\n+ a\nnomasterpoll = maybe\n"))->reason)->toBe("syntax error in value of 'nomasterpoll'")
			->and(sp_error(sp_config("probe = FPing\nmenu = m\ntitle = t\n+ a\nslaves = a,b\n"))->reason)->toBe('Use the format: slaves=[-_0-9a-zA-Z.]+ [slave2]')
			->and(sp_error(sp_config('', database: "step = 0\npings = 20\n"))->reason)->toBe('must be an integer >= 1')
			->and(sp_error(sp_config('', database: "step = 300\npings = 2\n"))->reason)->toBe('ERROR: The pings value must be at least 3.')
			->and(sp_error(sp_config('', database: "step = 300\npings = 20\nAVERAGE 0.5 1\n"))->reason)->toBe('row must have 4 columns (has 3)')
			->and(sp_error(sp_config('', probes: "+ DismanPing\npings = 16\n"))->reason)->toBe('ERROR: for DismanPing, pings must be between 1 and 15.')
			->and(sp_error(sp_config("probe = FPing\nmenu = m\ntitle = t\n+ PROBE_CONF\n"))->reason)->toContain('PROBE_CONF sections are neither needed nor supported');
	});

	it('names no line for a missing mandatory variable, and gives the section', function () {
		$e = sp_error(sp_config("probe = FPing\nmenu = m\ntitle = t\n", database: "step = 300\n"));

		expect($e->sourceLine)->toBe(0)
			->and($e->getMessage())->toBe("test.conf (Database): mandatory variable 'pings' not defined");
	});

	it('names the last physical line of a continued line', function () {
		$text = sp_config("probe = FPing\nmenu = m\ntitle = t\n+ a\nhide = \\\n  perhaps\n");

		expect(sp_error($text)->sourceLine)->toBe(count(explode("\n", $text)) - 1);
	});

	it('refuses a variable before any section and in a section without variables', function () {
		expect(sp_error("owner = x\n")->reason)->toBe('no variables are allowed')
			->and(sp_error(str_replace("*** Probes ***\n", "*** Probes ***\nbinary = /x\n", sp_config('')))->reason)->toBe('no variables are allowed');
	});

	it('refuses an unknown probe module', function () {
		expect(sp_error(sp_config('', "+ NoSuchProbe\n"))->reason)->toContain("no SmokePing probe module named 'NoSuchProbe'");
	});
});

describe('SmokepingConfigParser includes and limits', function () {
	it('reads @include relative to the including file, nested and in subdirectories', function () {
		$main = sp_config("probe = FPing\nmenu = m\ntitle = t\n@include inc/europe\n");
		$dir  = sp_dir(['config' => $main, 'inc/europe' => "+ Europe\nmenu = e\n@include \"paris\n", 'inc/paris' => "++ paris\nhost = 192.0.2.1\n"]);

		$doc = SmokepingInventory::document((new SmokepingConfigParser())->parseFile("$dir/config"));

		expect(array_column($doc['targets'], 'path'))->toBe(['Europe/paris']);
	});

	it('names the included file and its own line in an error', function () {
		$dir = sp_dir(['config' => sp_config("probe = FPing\nmenu = m\ntitle = t\n@include part\n"), 'part' => "+ a\n\nhots = 1\n"]);

		try {
			(new SmokepingConfigParser())->parseFile("$dir/config");
			$e = null;
		} catch (SmokepingConfigError $e) {
		}

		expect($e?->source)->toBe("$dir/part")
			->and($e?->sourceLine)->toBe(3);
	});

	it('refuses an include outside the allowed directories, through a symlink too', function () {
		$outside = sp_dir(['secret' => "+ x\nhost = h\n"]);
		$dir     = sp_dir(['config' => sp_config("probe = FPing\nmenu = m\ntitle = t\n@include $outside/secret\n"), 'link.conf' => sp_config("probe = FPing\nmenu = m\ntitle = t\n@include ln\n")]);
		symlink("$outside/secret", "$dir/ln");

		expect(fn () => (new SmokepingConfigParser())->parseFile("$dir/config"))->toThrow(SmokepingConfigError::class, 'outside the allowed directories')
			->and(fn () => (new SmokepingConfigParser())->parseFile("$dir/link.conf"))->toThrow(SmokepingConfigError::class, 'outside the allowed directories')
			->and(fn () => (new SmokepingConfigParser([$dir, $outside]))->parseFile("$dir/config"))->not->toThrow(SmokepingConfigError::class);
	});

	it('refuses an include cycle and too deep a chain of includes', function () {
		$dir = sp_dir(['config' => sp_config("probe = FPing\nmenu = m\ntitle = t\n@include a\n"), 'a' => "@include b\n", 'b' => "@include a\n"]);

		expect(fn () => (new SmokepingConfigParser())->parseFile("$dir/config"))->toThrow(SmokepingConfigError::class, 'already being read');

		$files = ['config' => sp_config("probe = FPing\nmenu = m\ntitle = t\n@include i0\n")];
		for ($i = 0; $i < SmokepingConfigParser::MAX_INCLUDE_DEPTH + 1; $i++) {
			$files["i$i"] = '@include i' . ($i + 1) . "\n";
		}
		$files['i' . (SmokepingConfigParser::MAX_INCLUDE_DEPTH + 1)] = "\n";
		$dir = sp_dir($files);

		expect(fn () => (new SmokepingConfigParser())->parseFile("$dir/config"))->toThrow(SmokepingConfigError::class, 'nested deeper than');
	});

	it('refuses more includes than the limit', function () {
		$lines = str_repeat("@include empty\n", SmokepingConfigParser::MAX_INCLUDES + 1);
		$dir   = sp_dir(['config' => sp_config("probe = FPing\nmenu = m\ntitle = t\n$lines"), 'empty' => "\n"]);

		expect(fn () => (new SmokepingConfigParser())->parseFile("$dir/config"))->toThrow(SmokepingConfigError::class, 'more than ' . SmokepingConfigParser::MAX_INCLUDES);
	});

	it('refuses @include in an uploaded file, saying so', function () {
		expect(sp_error(sp_config("@include other\n"))->reason)->toContain('@include is not allowed here: an uploaded file is read on its own');
	});

	it('bounds the line length, the nesting depth and the file size', function () {
		expect(sp_error(sp_config('probe = FPing' . str_repeat(' ', SmokepingConfigParser::MAX_LINE) . "x\n"))->reason)->toContain('longer than')
			->and(fn () => (new SmokepingConfigParser())->parseString(str_repeat(' ', SmokepingConfigParser::MAX_FILE_BYTES + 1), 'big'))->toThrow(SmokepingConfigError::class, 'larger than');

		$deep = "probe = FPing\nmenu = m\ntitle = t\n";
		for ($i = 1; $i < SmokepingConfigParser::MAX_DEPTH + 1; $i++) {
			$deep .= str_repeat('+', $i) . " n$i\n";
		}

		expect(sp_error(sp_config($deep))->reason)->toContain('nested deeper than');
	});

	it('substitutes @define text in later lines', function () {
		$doc = sp_parse("@define HOSTNAME 192.0.2.7\n" . sp_config("probe = FPing\nmenu = m\ntitle = t\n+ a\nhost = HOSTNAME\n"));

		expect($doc['targets'][0]['host'])->toBe('192.0.2.7');
	});

	it('bounds a line that @define substitution lengthens, chained defines too', function () {
		$half = intdiv(SmokepingConfigParser::MAX_LINE, 2);

		// Each define fits on its line; used together they would grow one line
		// to several gigabytes.
		$grow = '@define AA ' . str_repeat('B', $half) . "\n@define B " . str_repeat('C', $half) . "\n";

		expect(sp_error($grow . sp_config("probe = FPing\nmenu = AA\ntitle = t\n"))->reason)->toContain('longer than ' . SmokepingConfigParser::MAX_LINE . ' bytes after @define substitution')
			->and(sp_error('@define X ' . str_repeat('Y', 100) . "\n" . sp_config('probe = FPing' . "\nmenu = " . str_repeat('X', $half) . "\n"))->reason)->toContain('after @define substitution');
	});

	it('refuses more defines than the limit, but not a define given again', function () {
		$defines = '';
		for ($i = 0; $i <= SmokepingConfigParser::MAX_DEFINES; $i++) {
			$defines .= "@define DEF$i x\n";
		}

		$again = str_repeat("@define SAME x\n", SmokepingConfigParser::MAX_DEFINES + 1);

		expect(sp_error($defines . sp_config("probe = FPing\nmenu = m\ntitle = t\n"))->reason)->toContain('more than ' . SmokepingConfigParser::MAX_DEFINES . ' @define lines')
			->and(sp_parse($again . sp_config("probe = FPing\nmenu = m\ntitle = t\n+ a\nhost = h\n"))['targets'])->toHaveCount(1);
	});

	it('reports a missing file as SmokePing does', function () {
		expect(fn () => (new SmokepingConfigParser())->parseFile('/nonexistent/smokeping/config'))->toThrow(SmokepingConfigError::class, "can't open /nonexistent/smokeping/config");
	});
});

describe('SmokepingConfigReader', function () {
	it('turns the file into an Inventory and a parse error into an InventoryError', function () {
		$dir = sp_dir(['config' => sp_config("probe = FPing\nmenu = m\ntitle = t\n+ a\nhost = 192.0.2.1\n"), 'bad' => "hots = 1\n"]);

		$inventory = (new SmokepingConfigReader("$dir/config"))->read(3);

		expect($inventory)->toBeInstanceOf(Inventory::class)
			->and($inventory->targets[0]->path->value)->toBe('a')
			->and($inventory->targets[0]->pollerId)->toBe(3)
			->and($inventory->datadir)->toBe('/var/lib/smokeping')
			->and(fn () => (new SmokepingConfigReader("$dir/bad"))->read(1))->toThrow(Somethingwithproof\Smokeping\InventoryError::class, "$dir/bad, line 1: no variables are allowed");
	});
});
