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

/*
 * Deterministic generator of SmokePing configurations for the differential
 * parser tests (tests/oracle/run.sh).
 *
 *   php tests/oracle/generate.php [--seed=N] [--valid=N] [--broken=N] [--big=N] <out-dir>
 *
 * Writes:
 *   env/              the files and directories SmokePing's parser checks for
 *   valid/NNNN/       random valid configurations, some split over @include files
 *   broken/NNNN/      a valid configuration with one fault on a known line
 *   valid.list, broken.list, big.list
 *   big/config        one configuration with N targets (100 per section)
 *
 * Every construct the plugin's parser handles appears: *** and + to +++++
 * sections, continuation lines, @include (relative, nested, in a
 * subdirectory), comments, CRLF line ends, probes with instances, Database
 * step, pings and RRA rows, Slaves with target slaves and nomasterpoll,
 * Presentation, General datadir and concurrentprobes, inherited probe, host
 * and pings, multi-host targets, and fork and non-fork probes.
 *
 * The random source is a fixed 64-bit generator, so a seed gives the same
 * files on every PHP version.
 */

final class Rng {
	private int $state;

	public function __construct(int $seed) {
		$this->state = ($seed & 0xFFFFFFFF) ?: 0x9E3779B9;
	}

	public function int(int $min, int $max): int {
		// xorshift32: 32-bit arithmetic only, so no PHP version or platform changes the sequence.
		$x = $this->state;
		$x ^= ($x << 13) & 0xFFFFFFFF;
		$x ^= $x >> 17;
		$x ^= ($x << 5) & 0xFFFFFFFF;
		$this->state = $x;

		return $min + $x % ($max - $min + 1);
	}

	public function chance(int $percent): bool {
		return $this->int(1, 100) <= $percent;
	}

	/**
	 * @template T
	 * @param list<T> $items
	 * @return T
	 */
	public function pick(array $items): mixed {
		return $items[$this->int(0, count($items) - 1)];
	}
}

$opts = [];
$out  = null;

foreach (array_slice($argv, 1) as $arg) {
	if (preg_match('/^--(seed|valid|broken|big)=(\d+)$/', $arg, $m) === 1) {
		$opts[$m[1]] = $m[2];
	} else {
		$out = $arg;
	}
}

if ($out === null) {
	fwrite(STDERR, "usage: generate.php <out-dir> [--seed=N] [--valid=N] [--broken=N] [--big=N]\n");
	exit(2);
}

$seed   = (int) ($opts['seed'] ?? 20261113);
$valid  = (int) ($opts['valid'] ?? 1000);
$broken = (int) ($opts['broken'] ?? 200);
$big    = (int) ($opts['big'] ?? 10000);

@mkdir($out, 0755, true);
$out = realpath($out);
$env = "$out/env";

foreach (['cache', 'data', 'run', 'pages'] as $dir) {
	@mkdir("$env/$dir", 0755, true);
}

foreach (['smokemail', 'tmail', 'basepage.html'] as $file) {
	file_put_contents("$env/$file", "Subject: test\n\n");
}

file_put_contents("$env/secrets", "s1:secret\n");
chmod("$env/secrets", 0600);

foreach (['fping', 'fping6', 'dig', 'tcpping', 'traceroute', 'echoping', 'curl'] as $bin) {
	file_put_contents("$env/$bin", "#!/bin/sh\nexit 0\n");
	chmod("$env/$bin", 0755);
}

/**
 * Probe modules the generator uses: fork probes take a target pings.
 */
const MODULES = [
	'FPing'          => ['fork' => false, 'probe' => "binary = @ENV@/fping\n"],
	'FPing6'         => ['fork' => false, 'probe' => "binary = @ENV@/fping6\n"],
	'DNS'            => ['fork' => true, 'probe' => "binary = @ENV@/dig\n"],
	'TCPPing'        => ['fork' => true, 'probe' => "binary = @ENV@/tcpping\n"],
	'EchoPingHttp'   => ['fork' => true, 'probe' => ''],
	'Curl'           => ['fork' => true, 'probe' => "urlformat = http://%host%/\n"],
	'TraceroutePing' => ['fork' => true, 'probe' => "binary = @ENV@/traceroute\n"],
	'DismanPing'     => ['fork' => false, 'probe' => ''],
];

const RESERVED = ['probe', 'menu', 'title', 'alerts', 'note', 'email', 'host', 'remark', 'rawlog', 'alertee', 'slaves', 'menuextra', 'parents', 'hide', 'nomasterpoll', 'pings', 'lookup', 'server', 'port', 'desthost', 'maxttl', 'minttl', 'wait', 'pinghost', 'url', 'urlformat', 'timeout', 'PROBE_CONF'];

/**
 * One random valid configuration as a list of [line text, include file]
 * pairs; the include file is '' for the main file.
 */
function config(Rng $r, string $env): array {
	$lines = [];
	$add   = function (string $text) use (&$lines): void {
		foreach (explode("\n", rtrim($text, "\n")) as $line) {
			$lines[] = $line;
		}
	};
	$hdr = fn (string $name) => $r->pick(["*** $name ***", "***$name***", "*** $name***", "  ***  $name  ***"]);
	$comment = fn () => $r->chance(15) ? $r->pick(['# a comment', '   # indented comment', '#', '#*** not a section ***', '# + not a section']) : null;

	$concurrent = $r->pick([null, 'yes', 'no']);
	$add($hdr('General'));
	$add("owner    = Oracle Test\ncontact  = oracle@example.net\ncgiurl   = http://localhost/smokeping.cgi\nimgcache = $env/cache\nimgurl   = cache\ndatadir  = $env/data\npiddir   = $env/run\nsmokemail = $env/smokemail\ntmail = $env/tmail");
	if ($concurrent !== null) {
		$add("concurrentprobes = $concurrent");
	}
	if ($r->chance(30)) {
		$add("display_name = Oracle \\\n    master");
	}
	$add('');

	$add($hdr('Database'));
	$add('step = ' . $r->pick(['300', '60', '120', '600', '0300']));
	$add('pings = ' . $r->pick(['20', '10', '5', '3', '30']));
	$add('');
	$add('# consfn mrhb steps total');
	$add('AVERAGE  0.5   1  1008');
	foreach (['AVERAGE  0.5  12  4320', 'MIN  0.5  12  4320', 'MAX  0.5 144 720'] as $rra) {
		if ($r->chance(60)) {
			$add($rra);
		}
	}
	$add('');

	$add($hdr('Presentation'));
	$add("template = $env/basepage.html");
	$add('+ overview');
	$add("width = 600\nheight = 50\nrange = 10h");
	$add('+ detail');
	$add("width = 600\nheight = 200");
	$add($r->pick(['"Last 3 Hours"    3h', "'Last day' 1d", 'Week 7d']));
	if ($r->chance(50)) {
		$add('"Last 30 Days"   30d');
	}
	$add('');

	// Probes: each module either a probe itself or a template for instances.
	$add($hdr('Probes'));
	$probeNames = [];
	$fork       = [];
	$modules    = array_keys(MODULES);
	$chosen     = ['FPing'];
	foreach ($modules as $m) {
		if ($m !== 'FPing' && $r->chance(45)) {
			$chosen[] = $m;
		}
	}
	foreach ($chosen as $m) {
		$add('+ ' . $m);
		$add(str_replace('@ENV@', $env, MODULES[$m]['probe']));
		if ($m === 'DismanPing' && $r->chance(50)) {
			$add('pinghost = 192.0.2.250');
		}
		if ($m === 'TraceroutePing' && $r->chance(50)) {
			$add('desthost = 192.0.2.251');
		}
		// The ping probes' descriptions name the packet size, which instances inherit.
		if (in_array($m, ['FPing', 'FPing6', 'DismanPing'], true) && $r->chance(30)) {
			$add('packetsize = ' . $r->pick(['64', '100', '1000']));
		}
		if ($r->chance(30)) {
			$add('step = ' . $r->pick(['60', '120', '300', '30']));
		}
		if ($r->chance(30)) {
			// DismanPing takes 1 to 15 pings.
			$add('pings = ' . $r->pick($m === 'DismanPing' ? ['1', '5', '15'] : ['5', '10', '15', '20']));
		}
		if ($c = $comment()) {
			$add($c);
		}
		$instances = $r->chance(35) ? $r->int(1, 3) : 0;
		if ($instances === 0) {
			$probeNames[] = $m;
			$fork[$m]     = MODULES[$m]['fork'];
		}
		for ($i = 0; $i < $instances; $i++) {
			$name = $m . $r->pick(['Fast', 'Slow', 'Big', 'X', '-small', '_2', 'Normal']) . $i;
			$add('++ ' . $name);
			if ($r->chance(50)) {
				$add('step = ' . $r->pick(['60', '30', '120', '300']));
			}
			if ($r->chance(50)) {
				$add('pings = ' . $r->pick($m === 'DismanPing' ? ['3', '7', '15'] : ['3', '7', '10', '20']));
			}
			if (in_array($m, ['FPing', 'FPing6', 'DismanPing'], true) && $r->chance(30)) {
				$add('packetsize = ' . $r->pick(['72', '500']));
			}
			$probeNames[] = $name;
			$fork[$name]  = MODULES[$m]['fork'];
		}
	}
	$add('');

	$slaves = [];
	if ($r->chance(50)) {
		$add($hdr('Slaves'));
		$add("secrets = $env/secrets");
		$n = $r->int(1, 3);
		for ($i = 0; $i < $n; $i++) {
			$name = $r->pick(['boston', 'sydney.au', 'slave1', 'eu-west.1', 'tokyo']) . $i;
			$slaves[] = $name;
			$add("+ $name");
			$add("display_name = Slave $i\ncolor = " . $r->pick(['ff0000', '00ff00', '0000ff']));
			if ($r->chance(20)) {
				$add("location = Somewhere \\\n far");
			}
		}
		$add('');
	}

	// Alerts, and targets that name them, inherit them, or clear them with an
	// empty value.
	$alerts = [];
	if ($r->chance(40)) {
		$add($hdr('Alerts'));
		$add("to = alertee@example.net\nfrom = smokealert@example.net");
		$add("+someloss\ntype = loss\npattern = >0%,*12*,>0%,*12*,>0%\ncomment = loss 3 times in a row");
		$add("+bigloss\ntype = loss\npattern = ==0%,==0%,==0%,==0%,>50%,>50%,>50%\ncomment = suddenly there is packet loss");
		$alerts = ['someloss', 'bigloss'];
		$add('');
	}

	// Targets.
	$add($hdr('Targets'));
	$topProbe = $r->pick($probeNames);
	$add("probe = $topProbe");
	$add('menu = Top');
	$add($r->pick(['title = Oracle', "title = Oracle \\\n  network \\\n  latency"]));
	if ($slaves !== [] && $r->chance(20)) {
		$add('slaves = ' . $slaves[0]);
	}
	if ($alerts !== [] && $r->chance(30)) {
		$add('alerts = someloss');
	}
	if ($fork[$topProbe] && $r->chance(30)) {
		$add('pings = ' . $r->pick(['4', '8', '12']));
	}
	$add('');

	$used = [];
	$tree = function (int $level, string $ctx, bool $hostInherited) use (&$tree, $r, $add, &$used, $probeNames, $fork, $slaves, $alerts, $comment, $env): void {
		$count = $level === 2 ? $r->int(1, 5) : $r->int(0, max(0, 4 - $level + 1));
		$names = [];
		for ($i = 0; $i < $count; $i++) {
			do {
				$name = $r->pick(['', 'n', 'N', 'x', 'Z', '_', '-', '1', 'a-', 'B_']) . $r->pick(['a', 'b', 'B', 'z', '9', '0', '_', '-', 'Q']) . $r->int(0, 99);
			} while (in_array($name, RESERVED, true) || isset($names[$name]));
			$names[$name] = true;

			$add(str_repeat('+', $level - 1) . $r->pick([' ', '', '  ']) . $name);
			if ($c = $comment()) {
				$add($c);
			}

			$probe = $ctx;
			if ($r->chance(20)) {
				$probe = $r->pick($probeNames);
				$add("probe = $probe");
			}
			$module = preg_replace('/(Fast|Slow|Big|X|-small|_2|Normal)\d$/', '', $probe);
			if ($r->chance(70)) {
				$add('menu = ' . $r->pick(["Menu $name", "M\\\n $name", '']));
			}
			if ($r->chance(60)) {
				$add('title = ' . $r->pick(["Title of $name", "Title \\\n of \\\n $name", "Title   with   spaces", "T # trailing comment"]));
			}

			$hasHost = false;
			if ($r->chance(65)) {
				$host = $r->pick(['192.0.2.' . $r->int(1, 254), '198.51.100.' . $r->int(1, 254), '2001:db8::' . $r->int(1, 999), 'host' . $r->int(1, 50) . '.example', 'DYNAMIC', 'localhost']);
				if ($r->chance(8)) {
					$host = '/' . $name . '/x /other/y';
				}
				$add("host = $host");
				$hasHost = true;
				if ($module === 'DismanPing') {
					$add('pinghost = 192.0.2.' . $r->int(1, 254));
				}
				if ($module === 'TraceroutePing') {
					$add('desthost = 192.0.2.' . $r->int(1, 254));
				}
			}
			if ($fork[$probe] ?? false) {
				if ($r->chance(30)) {
					$add('pings = ' . $r->pick(['3', '5', '11', '25']));
				}
				if ($module === 'DNS' && $r->chance(30)) {
					$add('lookup = example.net');
				}
			}
			if ($slaves !== [] && $r->chance(25)) {
				$pickSlaves = [];
				foreach ($slaves as $s) {
					if ($r->chance(50)) {
						$pickSlaves[] = $s;
					}
				}
				$add('slaves = ' . implode($r->pick([' ', '  ', "\t"]), $pickSlaves));
			}
			if ($r->chance(15)) {
				$add('nomasterpoll = ' . $r->pick(['yes', 'no']));
			}
			if ($alerts !== [] && $r->chance(25)) {
				$add('alerts = ' . $r->pick(['bigloss', 'someloss,bigloss', '']));
			}
			if ($r->chance(10)) {
				$add('hide = ' . $r->pick(['yes', 'no']));
			}
			if ($r->chance(10)) {
				$add('remark = A remark \\');
				$add('   that continues');
			}
			if ($level < 6) {
				$tree($level + 1, $probe, $hostInherited || ($hasHost && $module === 'TraceroutePing'));
			}
		}
	};
	$tree(2, $topProbe, false);

	return $lines;
}

/**
 * Write a configuration, optionally split over @include files, and return
 * the main file's path and where each logical line landed.
 *
 * @param list<string> $lines
 * @return array{string, array<int, array{string, int}>}
 */
function write_config(Rng $r, string $dir, array $lines, bool $split): array {
	@mkdir($dir, 0755, true);
	$eol   = $r->chance(10) ? "\r\n" : "\n";
	$files = ['config' => []];
	$where = [];

	if ($split) {
		// Cut before *** sections and before top-level targets.
		$current = 'config';
		$n       = 0;
		foreach ($lines as $i => $line) {
			$cut = preg_match('/^\s*\*\*\*/', $line) === 1 || preg_match('/^\+[^+]/', $line) === 1;
			if ($cut && $r->chance(25)) {
				$n++;
				$name = $r->pick(['inc/part', 'part', 'inc/sub/part']) . $n;
				$parent = $current === 'config' || $r->chance(50) ? 'config' : $current;
				$rel    = $parent === 'config' ? $name : relative(dirname($parent), $name);
				$files[$parent][] = [$r->pick(['@include ', '@include  ', '@include "']) . $rel, -1];
				if (str_ends_with(end($files[$parent])[0], '"' . $rel) && str_contains(end($files[$parent])[0], '"')) {
					// Config::Grammar keeps a closing quote in the name, so a quoted include has no closing quote.
				}
				$files[$name] = [];
				$current      = $name;
			}
			$files[$current][] = [$line, $i];
		}
	} else {
		foreach ($lines as $i => $line) {
			$files['config'][] = [$line, $i];
		}
	}

	foreach ($files as $name => $content) {
		$path = "$dir/$name";
		@mkdir(dirname($path), 0755, true);
		$text = '';
		$no   = 0;
		foreach ($content as [$line, $i]) {
			$no++;
			$text .= $line . $eol;
			if ($i >= 0) {
				$where[$i] = [$path, $no];
			}
		}
		file_put_contents($path, $text);
	}

	return ["$dir/config", $where];
}

function relative(string $fromDir, string $to): string {
	if ($fromDir === '.') {
		return $to;
	}

	return str_repeat('../', substr_count($fromDir, '/') + 1) . $to;
}

/**
 * One fault applied to a valid configuration's lines.
 *
 * @param list<string> $lines
 * @return array{list<string>, string}
 */
function break_config(Rng $r, array $lines, int $kind): array {
	$find = function (string $re) use ($lines, $r): int {
		$hits = [];
		foreach ($lines as $i => $line) {
			if (preg_match($re, $line) === 1) {
				$hits[] = $i;
			}
		}

		return $hits === [] ? -1 : $r->pick($hits);
	};
	$insertAfter = function (int $i, string $text) use (&$lines): void {
		array_splice($lines, $i + 1, 0, [$text]);
	};
	$targets = $find('/^\s*\*\*\*\s*Targets/');
	$hostAt  = $find('/^host = [0-9]/');

	switch ($kind) {
		case 0:
			$insertAfter($targets - 1, '*** Nonsense ***');

			return [$lines, 'unknown section'];
		case 1:
			$at = $find('/^\+[^+]/');
			$insertAfter($at, '+++ deep');

			return [$lines, 'nesting error'];
		case 2:
			$insertAfter($find('/^\s*\*\*\*\s*Probes/') - 1, '*** Database ***');

			return [$lines, 'duplicate section'];
		case 3:
			$at = $find('/^menu = /');
			$insertAfter($at, 'menu = again');

			return [$lines, 'duplicate variable'];
		case 4:
			$insertAfter($find('/^\+{1,5}\s*\S/') , 'hots = 192.0.2.9');

			return [$lines, 'unknown variable'];
		case 5:
			// The Database pings is the first.
			foreach ($lines as $i => $line) {
				if (str_starts_with($line, 'pings = ')) {
					$lines[$i] = 'pings = 2';

					break;
				}
			}

			return [$lines, 'Database pings below 3'];
		case 6:
			// The Database step is the first; a probe step of 0 is valid.
			foreach ($lines as $i => $line) {
				if (str_starts_with($line, 'step = ')) {
					$lines[$i] = 'step = 0';

					break;
				}
			}

			return [$lines, 'Database step 0'];
		case 7:
			$lines[$find('/^probe = /')] = 'probe = NoSuchProbe';

			return [$lines, 'probe missing'];
		case 8:
			$at = $find('/^\+{1,5}\s*\S/');
			$insertAfter($at, 'host = 192.0.2.77');
			$insertAfter($at + 1, 'probe = FPing');

			return [$lines, 'probe after host'];
		case 9:
			// After a whole line: a line ending in a backslash continues into
			// the next, which would take the section line as value text.
			$at = $targets + 3;
			while (isset($lines[$at]) && str_ends_with(rtrim($lines[$at], ' '), '\\')) {
				$at++;
			}
			$insertAfter($at, '+ bad.section');

			return [$lines, 'bad section name'];
		case 10:
			$insertAfter($find('/^\+{1,5}\s*\S/'), 'nomasterpoll = maybe');

			return [$lines, 'nomasterpoll value'];
		case 11:
			$insertAfter($find('/^\+{1,5}\s*\S/'), 'slaves = a,b');

			return [$lines, 'slaves format'];
		case 12:
			$lines[$find('/^AVERAGE  0.5   1  1008/')] = 'AVERAGE 0.5 1';

			return [$lines, 'table columns'];
		case 13:
			$lines[$find('/^AVERAGE  0.5   1  1008/')] = 'MEDIAN 0.5 1 1008';

			return [$lines, 'table value'];
		case 14:
			$at = $targets;
			while (preg_match('/^\+{1,5}\s*\S/', $lines[$at]) !== 1) {
				$at++;
			}
			$lines[$at] = preg_replace('/\S+$/', 'PROBE_CONF', $lines[$at]);

			return [$lines, 'PROBE_CONF target section'];
		case 15:
			array_unshift($lines, 'owner = nobody');

			return [$lines, 'variable before any section'];
		case 16:
			$insertAfter($find('/^\s*\*\*\*\s*Probes/'), 'binary = /bin/true');

			return [$lines, 'variable in Probes'];
		case 17:
			$insertAfter($find('/^title = /'), "hide = \\\nperhaps");

			return [$lines, 'error on a continuation line'];
		case 18:
			$insertAfter($find('/^\s*\*\*\*\s*Probes/'), '+ NoSuchModule');

			return [$lines, 'unknown probe module'];
		case 19:
			$lines[$find('/^width = 600/')] = 'width = 5';

			return [$lines, 'width below 10'];
		case 20:
			$lines = array_values(array_filter($lines, fn (string $l) => !str_starts_with($l, 'pings = ') || !in_array($l, ['pings = 20', 'pings = 10', 'pings = 5', 'pings = 3', 'pings = 30'], true)));
			$db = array_search(true, array_map(fn ($l) => preg_match('/\*\*\*\s*Database/', $l) === 1, $lines), true);
			$out = [];
			$in  = false;
			foreach ($lines as $l) {
				if (preg_match('/\*\*\*\s*Database/', $l) === 1) {
					$in = true;
				} elseif (preg_match('/\*\*\*/', $l) === 1) {
					$in = false;
				}
				if (!($in && str_starts_with($l, 'pings ='))) {
					$out[] = $l;
				}
			}

			return [$out, 'mandatory variable missing'];
		case 21:
			$insertAfter($find('/^\+ overview/'), 'pings = 5 # the overview takes no pings');

			return [$lines, 'pings in the Presentation overview'];
		default:
			$insertAfter($find('/^\s*\*\*\*\s*Targets/'), '@include missing-file');

			return [$lines, 'include of a missing file'];
	}
}

$r = new Rng($seed);
$validList = [];

for ($n = 1; $n <= $valid; $n++) {
	$lines = config($r, $env);
	[$path] = write_config($r, sprintf('%s/valid/%04d', $out, $n), $lines, $r->chance(35));
	$validList[] = $path;
}

file_put_contents("$out/valid.list", implode("\n", $validList) . "\n");

$brokenList = [];
for ($n = 1; $n <= $broken; $n++) {
	$lines = config($r, $env);
	[$lines, $what] = break_config($r, $lines, ($n - 1) % 23);
	[$path] = write_config($r, sprintf('%s/broken/%04d', $out, $n), $lines, $r->chance(30));
	file_put_contents(dirname($path) . '/fault', $what . "\n");
	$brokenList[] = $path;
}

// Two include faults that need files: a cycle, and an include from a subdirectory.
$cycle = "$out/broken/cycle";
@mkdir("$cycle/inc", 0755, true);
$lines = config($r, $env);
[$path] = write_config($r, $cycle, $lines, false);
file_put_contents($path, (string) file_get_contents($path) . "@include inc/a\n");
file_put_contents("$cycle/inc/a", "@include b\n");
file_put_contents("$cycle/inc/b", "@include a\n");
file_put_contents("$cycle/fault", "include cycle\n");
$brokenList[] = $path;

file_put_contents("$out/broken.list", implode("\n", $brokenList) . "\n");

// The 10,000-target configuration, shaped like the one the Perl figure was measured on.
@mkdir("$out/big", 0755, true);
$text = "*** General ***\nowner = Oracle Test\ncontact = oracle@example.net\ncgiurl = http://localhost/smokeping.cgi\nimgcache = $env/cache\nimgurl = cache\n"
	. "datadir = $env/data\npiddir = $env/run\nsmokemail = $env/smokemail\n\n*** Database ***\nstep = 300\npings = 20\nAVERAGE 0.5 1 1008\n\n"
	. "*** Presentation ***\ntemplate = $env/basepage.html\n+ overview\nwidth = 600\nheight = 50\n+ detail\nwidth = 600\nheight = 200\n\n"
	. "*** Probes ***\n+ FPing\nbinary = $env/fping\n\n*** Targets ***\nprobe = FPing\nmenu = Top\ntitle = Big\n";
for ($s = 0; $s < intdiv($big, 100); $s++) {
	$text .= "+ s$s\nmenu = s$s\ntitle = s$s\n";
	for ($t = 0; $t < 100; $t++) {
		$text .= "++ t$t\nhost = 192.0.2." . ($t + 1) . "\n";
	}
}
file_put_contents("$out/big/config", $text);
file_put_contents("$out/big.list", "$out/big/config\n");

printf("generated %d valid, %d broken and one %d-target configuration under %s (seed %d)\n", count($validList), count($brokenList), $big, $out, $seed);
