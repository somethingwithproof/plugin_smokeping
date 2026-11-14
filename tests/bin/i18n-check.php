<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Thomas Vincent

/*
 * Checks that a page rendered in a language carries the plugin's translation
 * of a message from that language's catalog. Must parse on PHP 8.0.
 *
 *   php i18n-check.php <catalog.po> <msgid> <page.html>
 *
 * A %d or %s in the message matches any value. Prints the translation and
 * exits 0 when the page shows it, 1 when it does not.
 */

list(, $po, $msgid, $page) = $argv;

$translation = '';

if (preg_match('/^msgid "' . preg_quote(addcslashes($msgid, '"\\'), '/') . '"\nmsgstr "((?:[^"\\\\]|\\\\.)*)"/m', (string) file_get_contents($po), $m)) {
	$translation = stripcslashes($m[1]);
}

if ($translation === '' || $translation === $msgid) {
	echo "no translation of '$msgid' in $po\n";
	exit(1);
}

$pattern = '/' . str_replace(array('%d', '%s'), array('[0-9]+', '.+?'), preg_quote($translation, '/')) . '/u';
$html    = html_entity_decode((string) file_get_contents($page), ENT_QUOTES | ENT_HTML5, 'UTF-8');

echo $translation, PHP_EOL;
exit(preg_match($pattern, $html) ? 0 : 1);
