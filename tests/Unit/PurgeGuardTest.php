<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Thomas Vincent


use Somethingwithproof\Smokeping\CactiObjectRepository;
use Somethingwithproof\Smokeping\FixedClock;
use Somethingwithproof\Smokeping\ObjectRepository;
use Somethingwithproof\Smokeping\PurgeGuard;
use Somethingwithproof\Smokeping\Settings;
use Somethingwithproof\Smokeping\SmokeProvisioner;
use Somethingwithproof\Smokeping\Target;

const PG_RRA   = '/var/www/cacti/rra';
const PG_CACTI = '/var/www/cacti';

it('matches every spelling of a file under the mount', function (string $name) {
	$mount = PurgeGuard::normalize(PG_RRA . '/smokeping');

	expect(PurgeGuard::isUnder(PurgeGuard::resolve($name, PG_RRA, PG_CACTI), $mount))->toBeTrue($name);
})->with([
	'relative'            => 'smokeping/Europe/paris.rrd',
	'path_rra'            => '<path_rra>/smokeping/Europe/paris.rrd',
	'double slash'        => '<path_rra>/smokeping//Europe/paris.rrd',
	'dot segment'         => '<path_rra>/smokeping/./Europe/paris.rrd',
	'dot dot segment'     => '<path_rra>/smokeping/Europe/../Europe/paris.rrd',
	'expanded rra path'   => '/var/www/cacti/rra/smokeping/Europe/paris.rrd',
	'path_cacti'          => '<path_cacti>/rra/smokeping/Europe/paris.rrd',
	'leading slashes'     => '<path_rra>//smokeping/Europe/paris.rrd',
	'escapes and returns' => '<path_rra>/other/../smokeping/Europe/paris.rrd',
]);

it('does not match files outside the mount', function (string $name) {
	$mount = PurgeGuard::normalize(PG_RRA . '/smokeping');

	expect(PurgeGuard::isUnder(PurgeGuard::resolve($name, PG_RRA, PG_CACTI), $mount))->toBeFalse($name);
})->with([
	'prefix collision'   => 'smokeping-other/paris.rrd',
	'prefix collision 2' => '<path_rra>/smokeping-removed/1.rrd',
	'the mount itself'   => '<path_rra>/smokeping',
	'elsewhere'          => '<path_rra>/host/1.rrd',
	'dot dot out'        => '<path_rra>/smokeping/../host/1.rrd',
]);

/**
 * A rra_path with a mount, files, and the three ways a path goes wrong.
 */
function pg_tree(): string {
	$root = sys_get_temp_dir() . '/pg-' . bin2hex(random_bytes(4));

	mkdir($root . '/rra/smokeping/Europe', 0755, true);
	mkdir($root . '/outside', 0755, true);
	touch($root . '/rra/smokeping/Europe/ok.rrd', 0);
	touch($root . '/outside/x.rrd');
	symlink($root . '/outside/x.rrd', $root . '/rra/smokeping/Europe/file.rrd');
	symlink($root . '/outside', $root . '/rra/smokeping/Europe/dir');

	return $root;
}

function pg_target(string $path): Target {
	return Target::fromRow(['id' => 5, 'poller_id' => 1, 'path' => $path, 'host' => 'h', 'pings' => 20, 'step' => 300, 'present' => 1]);
}

it('refuses a file that is missing, symlinked, or reached through a symlinked directory', function () {
	$root     = pg_tree();
	$settings = Settings::fromRow(['smokeping_mount_path' => $root . '/rra/smokeping'], $root . '/rra');
	$cacti    = new CactiObjectRepository(false);
	$provider = new SmokeProvisioner($settings, $root . '/rra', '/nonexistent', false, new ObjectRepository(false), $cacti, new FixedClock(1));

	expect($provider->refusal(pg_target('Europe/ok')))->toBeNull()
		->and($provider->refusal(pg_target('Europe/missing')))->toContain('not visible')
		->and($provider->refusal(pg_target('Europe/file')))->toContain('symlink')
		->and($provider->refusal(pg_target('Europe/dir/x')))->toContain('symlink');

	exec('rm -rf ' . escapeshellarg($root));
});
