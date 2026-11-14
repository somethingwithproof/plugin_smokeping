<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Thomas Vincent

/*
 * Compare the oracle's output with the PHP parser's, line by line.
 *
 *   php tests/oracle/compare.php <label> <perl.jsonl> <php.jsonl> valid|broken
 *
 * valid: both parsers accept every file and the inventories match field for
 * field, apart from the SmokePing version, which only SmokePing knows.
 * broken: both refuse every file, and where SmokePing's error names a line,
 * the PHP error names the same file and line.
 *
 * Prints a RESULT line, and a DIFF line for each mismatch, and exits
 * non-zero on any mismatch.
 */

[, $label, $perlFile, $phpFile, $mode] = $argv + [null, null, null, null, null];

if (!in_array($mode, ['valid', 'broken'], true)) {
	fwrite(STDERR, "usage: compare.php <label> <perl.jsonl> <php.jsonl> valid|broken\n");
	exit(2);
}

$read = function (string $file): array {
	$out = [];

	foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
		$row = json_decode($line, true, 64, JSON_THROW_ON_ERROR);
		$out[$row['config']] = $row;
	}

	return $out;
};

/**
 * The file and line an error names, as "file:line", or '' for none.
 */
$place = function (string $error): string {
	return preg_match('/^ERROR: (.+?), line (\d+): /', $error, $m) === 1 ? $m[1] . ':' . $m[2] : '';
};

$normalise = function (array $inventory): array {
	unset($inventory['smokeping']);
	ksort($inventory);

	foreach ($inventory['targets'] as &$target) {
		ksort($target);
	}

	return $inventory;
};

$perl = $read($perlFile);
$php  = $read($phpFile);
$bad  = 0;
$same = 0;
$lines = 0;
$texts = 0;

foreach ($perl as $config => $p) {
	$q = $php[$config] ?? null;

	if ($q === null) {
		echo "DIFF $label $config: missing from the PHP output\n";
		$bad++;

		continue;
	}

	if ($mode === 'valid') {
		if (isset($p['error']) || isset($q['error'])) {
			echo "DIFF $label $config: perl " . ($p['error'] ?? 'accepted') . ' | php ' . ($q['error'] ?? 'accepted') . "\n";
			$bad++;

			continue;
		}

		$a = $normalise($p['inventory']);
		$b = $normalise($q['inventory']);

		if ($a !== $b) {
			foreach ($a['targets'] as $i => $t) {
				if (($b['targets'][$i] ?? null) !== $t) {
					echo "DIFF $label $config target $i: perl " . json_encode($t, JSON_UNESCAPED_SLASHES) . ' | php ' . json_encode($b['targets'][$i] ?? null, JSON_UNESCAPED_SLASHES) . "\n";

					break;
				}
			}

			if (count($a['targets']) !== count($b['targets']) || array_diff_key($a, ['targets' => 1]) !== array_diff_key($b, ['targets' => 1])) {
				echo "DIFF $label $config: perl " . count($a['targets']) . ' targets | php ' . count($b['targets']) . "\n";
			}

			$bad++;

			continue;
		}

		$same++;

		continue;
	}

	if (!isset($p['error']) || !isset($q['error'])) {
		echo "DIFF $label $config: perl " . ($p['error'] ?? 'accepted') . ' | php ' . ($q['error'] ?? 'accepted') . "\n";
		$bad++;

		continue;
	}

	$where = $place($p['error']);

	if ($where !== '') {
		$lines++;

		if ($place($q['error']) !== $where) {
			echo "DIFF $label $config: perl {$p['error']} | php {$q['error']}\n";
			$bad++;

			continue;
		}

		if ($p['error'] === $q['error']) {
			$texts++;
		}
	}

	$same++;
}

foreach (array_diff_key($php, $perl) as $config => $q) {
	echo "DIFF $label $config: missing from the oracle output\n";
	$bad++;
}

if ($mode === 'valid') {
	printf("RESULT %s %s-%s (%d of %d configurations give the same inventory)\n", $bad === 0 ? 'PASS' : 'FAIL', $label, $mode, $same, count($perl));
} else {
	printf("RESULT %s %s-%s (%d of %d refused by both; %d name a line, all the same line in both; %d with identical text)\n", $bad === 0 ? 'PASS' : 'FAIL', $label, $mode, $same, count($perl), $lines, $texts);
}

exit($bad === 0 ? 0 : 1);
