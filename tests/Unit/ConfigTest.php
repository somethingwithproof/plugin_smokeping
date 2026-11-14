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

use Cacti\Smokeping\AdminState;
use Cacti\Smokeping\ConfigCodec;
use Cacti\Smokeping\ConfigDocument;
use Cacti\Smokeping\ConfigException;
use Cacti\Smokeping\ConfigMapping;
use Cacti\Smokeping\ConfigPlanner;
use Cacti\Smokeping\ConfigState;
use Cacti\Smokeping\ConfigXml;
use Cacti\Smokeping\Target;
use Cacti\Smokeping\TargetId;
use Cacti\Smokeping\TargetMode;
use Cacti\Smokeping\TargetPath;

function smokeping_target(string $path, array $over = []): Target {
	$o = $over + ['poller' => 1, 'host' => 'h.example.net', 'probe' => 'FPing', 'menu' => '', 'title' => '', 'slaves' => [], 'nomaster' => false, 'step' => 300, 'pings' => 20, 'state' => AdminState::Enabled, 'present' => true, 'id' => null];

	return new Target($o['id'], $o['poller'], TargetPath::fromString($path), $o['host'], $o['probe'], $o['menu'], $o['title'], $o['slaves'], $o['nomaster'], $o['step'], $o['pings'], TargetMode::Observed, $o['state'], $o['present'], 0, 0);
}

function smokeping_document(): ConfigDocument {
	return new ConfigDocument(
		['smokeping_poller_id' => 1, 'smokeping_reader_budget' => 90, 'smokeping_uninstall_keep_tables' => true],
		[
			smokeping_target('Europe/Paris', ['title' => 'Paris: the "gateway" #1', 'menu' => 'yes', 'slaves' => ['site-b', 'a.b']]),
			smokeping_target('Europe/Rome', ['host' => 'rome.example.net', 'state' => AdminState::Disabled, 'present' => false, 'nomaster' => true, 'slaves' => ['site-b']]),
			smokeping_target('Other/x', ['poller' => 2, 'title' => '007']),
		],
		[new ConfigMapping(1, TargetPath::fromString('Europe/Paris'), 'paris.example.net', "Paris GW & 'co' <1>")],
		['smokeping_config_file' => '/etc/smokeping/config', 'smokeping_lib_dir' => '']
	);
}

/**
 * @return list<string>
 */
function smokeping_config_errors(string $format, string $text): array {
	try {
		ConfigCodec::parse($format, $text);
	} catch (ConfigException $e) {
		return $e->lines();
	}

	return [];
}

it('round-trips a document through both formats', function (string $format) {
	$document = smokeping_document();
	$text     = ConfigCodec::write($format, $document);

	expect(ConfigCodec::parse($format, $text))->toEqual($document)
		// A second write of what was read is byte for byte the same.
		->and(ConfigCodec::write($format, ConfigCodec::parse($format, $text)))->toBe($text);
})->with(['yaml', 'xml']);

it('reads the same document from YAML and from XML', function () {
	$document = smokeping_document();

	expect(ConfigCodec::parse('xml', ConfigCodec::write('xml', $document)))->toEqual(ConfigCodec::parse('yaml', ConfigCodec::write('yaml', $document)));
});

it('writes YAML a person can read', function () {
	$text = ConfigCodec::write('yaml', new ConfigDocument(['smokeping_poller_id' => 1], [smokeping_target('Europe/Paris', ['title' => 'Paris: gateway'])], []));

	expect($text)->toBe(<<<'YAML'
	schema_version: 1
	settings:
	  smokeping_poller_id: 1
	targets:
	  - path: Europe/Paris
	    poller_id: 1
	    host: h.example.net
	    probe: FPing
	    title: 'Paris: gateway'
	    nomasterpoll: false
	    step: 300
	    pings: 20
	    mode: observed
	    admin_state: enabled
	    present: true

	YAML);
});

it('reports every schema problem, with the line of each', function () {
	$errors = smokeping_config_errors('yaml', <<<'YAML'
	schema_version: 1
	settings:
	  smokeping_poller_id: 0
	  smokeping_nonsense: 1
	  smokeping_uninstall_keep_tables: 'on'
	  smokeping_datadir: /var/lib/smokeping
	targets:
	  - path: Europe/Paris
	    step: -1
	    mode: managed
	    admin_state: gone
	  - path: ../etc
	  - path: Europe/Paris
	  - path: Europe/Paris
	    bogus: 1
	mappings:
	  - target: Europe/Paris
	    device:
	      hostname: ''
	extra: 1
	YAML);

	$joined = implode("\n", $errors);

	expect($errors)->toContain('line 3: settings/smokeping_poller_id must be a whole number from 1 to 2147483647')
		->and($joined)->toContain('line 4: settings/smokeping_nonsense is not a known setting')
		->and($joined)->toContain('line 5: settings/smokeping_uninstall_keep_tables must be true or false')
		->and($joined)->toContain('line 6: settings/smokeping_datadir is reference only and is never imported')
		->and($joined)->toContain('line 9: targets/0/step must be a whole number from 0 to 604800')
		->and($joined)->toContain('line 10: targets/0/mode managed targets are not supported')
		->and($joined)->toContain('line 11: targets/0/admin_state must be enabled, disabled, or archived')
		->and($joined)->toContain('line 12: targets/1/path Invalid SmokePing section name')
		->and($joined)->toContain('line 14: targets/3 repeats target Europe/Paris on poller 1')
		->and($joined)->toContain('line 15: targets/3/bogus is not a known target field')
		->and($joined)->toContain('line 19: mappings/0/device/hostname must not be empty')
		->and($joined)->toContain('line 20: extra is not a known key');
});

it('refuses the keys reserved for managed mode', function (string $format, string $text) {
	$errors = implode("\n", smokeping_config_errors($format, $text));

	expect($errors)->toContain('is reserved for managed mode');
})->with([
	'yaml managed'  => ['yaml', "schema_version: 1\nmanaged_targets:\n  - path: A/b\n"],
	'yaml profiles' => ['yaml', "schema_version: 1\nprobe_profiles:\n  fast:\n    step: 10\n"],
	'xml managed'   => ['xml', '<smokeping-config schema_version="1"><managed_targets/></smokeping-config>'],
	'xml profiles'  => ['xml', '<smokeping-config schema_version="1"><probe_profiles><profile/></probe_profiles></smokeping-config>'],
]);

it('refuses a missing or newer schema version', function () {
	expect(implode(' ', smokeping_config_errors('yaml', "targets:\n  - path: A/b\n")))->toContain('schema_version is required')
		->and(implode(' ', smokeping_config_errors('yaml', "schema_version: 2\n")))->toContain('newer than this version understands')
		->and(implode(' ', smokeping_config_errors('yaml', "schema_version: '1'\n")))->toContain('schema_version is required')
		->and(implode(' ', smokeping_config_errors('yaml', "- a\n- b\n")))->toContain('must be a map');
});

it('does not read a file through an XML entity or a DOCTYPE', function (string $xml) {
	expect(fn () => ConfigXml::parse($xml))->toThrow(ConfigException::class);
})->with([
	'external entity' => ['<?xml version="1.0"?><!DOCTYPE c [<!ENTITY x SYSTEM "file:///etc/passwd">]><smokeping-config schema_version="1"><settings><setting name="smokeping_perl_binary">&x;</setting></settings></smokeping-config>'],
	'parameter entity' => ['<?xml version="1.0"?><!DOCTYPE c [<!ENTITY % x SYSTEM "http://127.0.0.1/x.dtd">%x;]><smokeping-config schema_version="1"/>'],
	'entity expansion' => ['<?xml version="1.0"?><!DOCTYPE c [<!ENTITY a "aaaaaaaaaa"><!ENTITY b "&a;&a;&a;&a;&a;&a;&a;&a;&a;&a;">]><smokeping-config schema_version="1"><settings><setting name="smokeping_perl_binary">&b;</setting></settings></smokeping-config>'],
	'bare doctype' => ['<!doctype smokeping-config><smokeping-config schema_version="1"/>'],
	'undefined entity' => ['<smokeping-config schema_version="1"><settings><setting name="smokeping_perl_binary">&x;</setting></settings></smokeping-config>'],
]);

it('refuses malformed, oversize, and unexpected XML', function (string $xml, string $fragment) {
	expect(fn () => ConfigXml::parse($xml, 4096))->toThrow(ConfigException::class, $fragment);
})->with([
	'not well formed' => ['<smokeping-config schema_version="1">', 'line'],
	'wrong root'      => ['<other/>', 'root element'],
	'unknown child'   => ['<smokeping-config schema_version="1"><settings><thing/></settings></smokeping-config>', 'Expected <setting>'],
	'bad attribute'   => ['<smokeping-config schema_version="1" x="1"/>', 'not allowed'],
	'text in a list'  => ['<smokeping-config schema_version="1"><targets>text</targets></smokeping-config>', 'Unexpected text'],
	'nested leaf'     => ['<smokeping-config schema_version="1"><settings><setting name="smokeping_perl_binary"><x/></setting></settings></smokeping-config>', 'cannot contain'],
	'processing instruction' => ['<smokeping-config schema_version="1"><?php echo 1; ?></smokeping-config>', 'Only elements'],
	'too big'         => ['<smokeping-config schema_version="1"><!--' . str_repeat('x', 5000) . '--></smokeping-config>', 'byte limit'],
	'invalid utf-8'   => ["<smokeping-config schema_version=\"1\"><!--\xFF--></smokeping-config>", 'UTF-8'],
]);

it('refuses XML nested deeper than the limit', function () {
	$xml = '<smokeping-config schema_version="1">' . str_repeat('<a>', 12) . str_repeat('</a>', 12) . '</smokeping-config>';

	expect(fn () => ConfigXml::parse($xml))->toThrow(ConfigException::class, 'Nesting is deeper than 8 levels');
});

it('names the line of an XML schema problem', function () {
	$xml = "<smokeping-config schema_version=\"1\">\n<targets>\n<target path=\"A/b\" step=\"x\"/>\n</targets>\n</smokeping-config>";

	expect(smokeping_config_errors('xml', $xml))->toBe(['line 3: targets/0/step must be a whole number from 0 to 604800']);
});

it('refuses a file over the size limit before reading it', function () {
	$file = tempnam(sys_get_temp_dir(), 'smokeping-cfg');

	file_put_contents($file, str_repeat('#', ConfigCodec::MAX_BYTES + 1));

	try {
		expect(fn () => ConfigCodec::readFile($file))->toThrow(ConfigException::class, 'byte limit')
			->and(fn () => ConfigCodec::readFile($file . '.missing'))->toThrow(ConfigException::class, 'Cannot read');
	} finally {
		unlink($file);
	}
});

/**
 * An installation holding Europe/Paris (id 7), mapped to device 40.
 */
function smokeping_state(array $over = []): ConfigState {
	$paris = smokeping_target('Europe/Paris', ['id' => TargetId::fromInt(7), 'title' => 'Old title']);

	return new ConfigState(
		$over['settings'] ?? ['smokeping_poller_id' => 1, 'smokeping_reader_budget' => 120, 'smokeping_mount_path' => '/var/www/cacti/rra/smokeping', 'smokeping_uninstall_keep_tables' => false],
		['1:Europe/Paris' => $paris],
		$over['mappings'] ?? ['1:Europe/Paris' => ['host_id' => 40, 'hostname' => 'paris.example.net', 'description' => 'Paris GW']],
		[1 => true],
		$over['devices'] ?? [ConfigState::deviceKey('paris.example.net', 'Paris GW') => [40], ConfigState::deviceKey('rome.example.net', 'Rome GW') => [41], ConfigState::deviceKey('dup.example.net', 'D') => [50, 51]],
		'/var/www/cacti/rra/smokeping'
	);
}

it('plans adds and changes and leaves what matches alone', function () {
	$document = new ConfigDocument(
		['smokeping_reader_budget' => 90, 'smokeping_poller_id' => 1],
		[smokeping_target('Europe/Paris', ['title' => 'New title']), smokeping_target('Europe/Rome')],
		[new ConfigMapping(1, TargetPath::fromString('Europe/Paris'), 'PARIS.example.net', 'Paris GW'), new ConfigMapping(1, TargetPath::fromString('Europe/Rome'), 'rome.example.net', 'Rome GW')]
	);

	$plan = ConfigPlanner::plan($document, smokeping_state());

	expect($plan->errors)->toBe([])
		->and($plan->settings)->toBe([['name' => 'smokeping_reader_budget', 'from' => 120, 'to' => 90]])
		->and(array_map(fn ($t) => $t->path->value, $plan->targetAdds))->toBe(['Europe/Rome'])
		->and($plan->targetChanges[0]['fields'])->toBe(['title' => ['from' => 'Old title', 'to' => 'New title']])
		->and($plan->mappingAdds)->toBe([['poller_id' => 1, 'path' => 'Europe/Rome', 'host_id' => 41, 'hostname' => 'rome.example.net', 'description' => 'Rome GW']])
		->and($plan->unchanged)->toBe(1)
		->and($plan->lines())->toContain('change setting smokeping_reader_budget: 120 -> 90')
		->and($plan->id())->toHaveLength(16)
		->and(ConfigPlanner::plan($document, smokeping_state())->id())->toBe($plan->id());
});

it('changes the plan id when anything it would do changes', function () {
	$a = new ConfigDocument(['smokeping_reader_budget' => 90], [], []);
	$b = new ConfigDocument(['smokeping_reader_budget' => 91], [], []);

	expect(ConfigPlanner::plan($a, smokeping_state())->id())->not->toBe(ConfigPlanner::plan($b, smokeping_state())->id());
});

it('finds nothing to do when the file matches', function () {
	$state = smokeping_state();
	$plan  = ConfigPlanner::plan(ConfigPlanner::export($state), $state);

	expect($plan->isEmpty())->toBeTrue()->and($plan->errors)->toBe([])->and($plan->unchanged)->toBe(2);
});

it('refuses mappings it cannot resolve or would have to move', function () {
	$mapping  = fn (string $path, string $host, string $description) => new ConfigMapping(1, TargetPath::fromString($path), $host, $description);
	$document = new ConfigDocument([], [smokeping_target('Europe/Rome')], [
		$mapping('Europe/Rome', 'nobody.example.net', 'x'),
		$mapping('Europe/Rome', 'dup.example.net', 'D'),
		$mapping('Europe/Paris', 'rome.example.net', 'Rome GW'),
		$mapping('Missing/target', 'paris.example.net', 'Paris GW'),
	]);

	$errors = implode("\n", ConfigPlanner::plan($document, smokeping_state())->errors);

	expect($errors)->toContain('no device has host name "nobody.example.net"')
		->and($errors)->toContain('more than one device has host name "dup.example.net" and description "D"; rename one so the name is unique')
		->and($errors)->toContain('an import never moves a mapping')
		->and($errors)->toContain('Missing/target on poller 1: no such target');
});

it('refuses a poller this installation does not have', function () {
	$document = new ConfigDocument(['smokeping_poller_id' => 9], [smokeping_target('A/b', ['poller' => 3])], []);
	$errors   = implode("\n", ConfigPlanner::plan($document, smokeping_state())->errors);

	expect($errors)->toContain('names poller 9')->and($errors)->toContain('names poller 3');
});

it('exports without the default mount path, and with mappings by device name', function () {
	$document = ConfigPlanner::export(smokeping_state());

	expect($document->settings)->not->toHaveKey('smokeping_mount_path')
		->and($document->settings['smokeping_reader_budget'])->toBe(120)
		->and($document->mappings[0]->hostname)->toBe('paris.example.net')
		->and(ConfigCodec::write('yaml', $document))->not->toContain('host_id');

	$custom = ConfigPlanner::export(smokeping_state(['settings' => ['smokeping_mount_path' => '/data/rra/sp']]));

	expect($custom->reference['smokeping_mount_path'])->toBe('/data/rra/sp')->and($custom->settings)->not->toHaveKey('smokeping_mount_path');
});

it('accepts the examples in docs/configuration-format.md', function () {
	$doc = (string) file_get_contents(dirname(__DIR__, 2) . '/docs/configuration-format.md');

	foreach (['yaml', 'xml'] as $format) {
		expect(preg_match('/```' . $format . "\n(.*?)\n```/s", $doc, $m))->toBe(1);

		$parsed = ConfigCodec::parse($format, $m[1]);

		expect($parsed->targets[0]->path->value)->toBe('Europe/Paris')
			->and($parsed->targets[0]->title)->toBe('Paris: gateway')
			->and($parsed->mappings[0]->hostname)->toBe('paris-gw.example.net');
	}
});

it('treats every path or program setting as reference only, from the schema and not from a list', function () {
	$paths = ['smokeping_config_file', 'smokeping_perl_binary', 'smokeping_lib_dir', 'smokeping_datadir', 'smokeping_mount_path'];

	foreach (Cacti\Smokeping\ConfigSchema::SETTINGS as $name => $spec) {
		expect(Cacti\Smokeping\ConfigSchema::isReferenceOnly($name))->toBe(in_array($spec[0], ['path', 'optional_path'], true), $name);
	}

	foreach ($paths as $name) {
		expect(Cacti\Smokeping\ConfigSchema::isReferenceOnly($name))->toBeTrue($name)
			->and(implode(' ', smokeping_config_errors('yaml', "schema_version: 1\nsettings:\n  $name: /tmp/x\n")))->toContain('is reference only and is never imported');
	}

	expect(Cacti\Smokeping\ConfigSchema::isReferenceOnly('smokeping_reader_budget'))->toBeFalse()
		->and(implode(' ', smokeping_config_errors('yaml', "schema_version: 1\nreference_settings:\n  smokeping_reader_budget: 5\n")))->toContain('not a reference-only setting')
		->and(implode(' ', smokeping_config_errors('xml', '<smokeping-config schema_version="1"><settings><setting name="smokeping_perl_binary">/tmp/x</setting></settings></smokeping-config>')))->toContain('is reference only');
});

it('reports a differing reference setting as not applied and plans no write for it', function () {
	$document = new ConfigDocument([], [], [], ['smokeping_perl_binary' => '/tmp/evil', 'smokeping_datadir' => '/var/lib/smokeping']);
	$state    = smokeping_state(['settings' => ['smokeping_poller_id' => 1, 'smokeping_perl_binary' => '/usr/bin/perl', 'smokeping_datadir' => '/var/lib/smokeping']]);
	$plan     = ConfigPlanner::plan($document, $state);

	expect($plan->settings)->toBe([])
		->and($plan->isEmpty())->toBeTrue()
		->and($plan->notApplied)->toBe([['name' => 'smokeping_perl_binary', 'from' => '/usr/bin/perl', 'to' => '/tmp/evil']])
		->and($plan->lines())->toContain('not applied, change on the Settings page: smokeping_perl_binary: "/usr/bin/perl" -> "/tmp/evil"');
});

it('writes reference settings in their own section in both formats and reads them back', function (string $format) {
	$text = ConfigCodec::write($format, smokeping_document());

	expect($text)->toContain('reference_settings')
		->and(ConfigCodec::parse($format, $text)->reference)->toBe(['smokeping_config_file' => '/etc/smokeping/config', 'smokeping_lib_dir' => '']);
})->with(['yaml', 'xml']);

function smokeping_backup_service(): Cacti\Smokeping\ConfigService {
	return new Cacti\Smokeping\ConfigService(new Cacti\Smokeping\ConfigRepository(false, '/var/www/cacti/rra'));
}

function smokeping_tmp_dir(): string {
	$dir = sys_get_temp_dir() . '/smokeping-backup-' . bin2hex(random_bytes(4));

	mkdir($dir, 0700);

	return $dir;
}

it('writes the backup under a random name, mode 0600, in a directory a web server will not serve', function () {
	$base    = smokeping_tmp_dir();
	$default = $base . '/cache/smokeping';
	$service = smokeping_backup_service();

	$first  = $service->writeBackup($default, '', 1700000000);
	$second = $service->writeBackup($default, '', 1700000000);

	expect($first)->toMatch('#/smokeping-config-20231114-221320-[0-9a-f]{16}\.yaml$#')
		->and($second)->not->toBe($first)
		->and(substr(sprintf('%o', fileperms((string) $first)), -4))->toBe('0600')
		->and((string) file_get_contents((string) $first))->toStartWith('schema_version: 1')
		->and((string) file_get_contents($default . '/.htaccess'))->toContain('Require all denied')->toContain('Deny from all')
		->and((string) file_get_contents($default . '/index.php'))->toContain('http_response_code(403)')->not->toContain('header(');
});

it('writes to the configured backup directory and nowhere else', function () {
	$base   = smokeping_tmp_dir();
	$custom = $base . '/backups';

	mkdir($custom);

	$path = smokeping_backup_service()->writeBackup($base . '/cache/smokeping', $custom . '/', 1700000000);

	expect(dirname((string) $path))->toBe($custom)
		->and(is_dir($base . '/cache'))->toBeFalse()
		->and(file_exists($custom . '/.htaccess'))->toBeFalse();
});

it('writes nothing, and logs it, when the configured backup directory is unusable', function () {
	$base                      = smokeping_tmp_dir();
	$GLOBALS['__smokeping_log'] = [];

	touch($base . '/a-file');

	foreach ([$base . '/missing', $base . '/a-file'] as $custom) {
		expect(smokeping_backup_service()->writeBackup($base . '/cache/smokeping', $custom, 1700000000))->toBeNull();
	}

	$messages = implode("\n", array_column($GLOBALS['__smokeping_log'], 'message'));

	expect(is_dir($base . '/cache'))->toBeFalse()
		->and(glob($base . '/*.yaml'))->toBe([])
		->and($messages)->toContain('is not a writable directory; no export was written');
});

it('treats the backup directory as a reference-only setting', function () {
	expect(Cacti\Smokeping\ConfigSchema::isReferenceOnly('smokeping_backup_dir'))->toBeTrue()
		->and(Cacti\Smokeping\Settings::fromRow(['smokeping_backup_dir' => '/srv/backups/'], '/r')->backupDir)->toBe('/srv/backups');
});

it('refuses export to a user who cannot see every device, and names no device', function () {
	$repository = new Cacti\Smokeping\ConfigRepository(false, '/var/www/cacti/rra');
	$refused    = new Cacti\Smokeping\ConfigService($repository, fn () => false);

	try {
		$refused->export('yaml');
		$message = '';
	} catch (ConfigException $e) {
		$message = $e->getMessage();
	}

	expect($message)->toContain('do not cover every device')
		->and((new Cacti\Smokeping\ConfigService($repository, fn () => true))->export('yaml'))->toStartWith('schema_version: 1')
		->and((new Cacti\Smokeping\ConfigService($repository))->export('yaml'))->toStartWith('schema_version: 1');
});

it('keeps the identity of devices the file does not name out of plan errors', function () {
	$mapping  = fn (string $host, string $description) => new ConfigMapping(1, TargetPath::fromString('Europe/Paris'), $host, $description);
	$document = new ConfigDocument([], [], [$mapping('dup.example.net', 'D')]);
	$moved    = new ConfigDocument([], [], [$mapping('rome.example.net', 'Rome GW')]);

	$errors = implode("\n", array_merge(ConfigPlanner::plan($document, smokeping_state())->errors, ConfigPlanner::plan($moved, smokeping_state())->errors));

	// The file's own names appear, as they are the file's text. Nothing from the installation does:
	// not the ids, and not the host name or description of the device a target is mapped to.
	expect($errors)->not->toMatch('/\b(40|41|50|51)\b/')
		->and($errors)->not->toContain('paris.example.net')
		->and($errors)->not->toContain('Paris GW');
});

it('refuses plan and apply to a user who cannot see every device, before reading anything', function () {
	$service  = new Cacti\Smokeping\ConfigService(new Cacti\Smokeping\ConfigRepository(false, '/var/www/cacti/rra'), fn () => false);
	$document = new ConfigDocument([], [], []);
	$GLOBALS['__smokeping_db_exec'] = [];

	expect(fn () => $service->plan($document))->toThrow(ConfigException::class, 'do not cover every device')
		->and(fn () => $service->apply($document, '0000000000000000', 1, 1))->toThrow(ConfigException::class, 'do not cover every device')
		->and($GLOBALS['__smokeping_db_exec'])->toBe([]);
});

it('revokes access between the check and the apply', function () {
	$allowed  = true;
	$service  = new Cacti\Smokeping\ConfigService(new Cacti\Smokeping\ConfigRepository(false, '/var/www/cacti/rra'), function () use (&$allowed) {
		return $allowed;
	});
	$document = new ConfigDocument([], [], []);
	$plan     = $service->plan($document);

	$allowed = false;

	expect(fn () => $service->apply($document, $plan->id(), 1, 1))->toThrow(ConfigException::class, 'do not cover every device');
});
