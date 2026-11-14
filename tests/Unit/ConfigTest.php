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
		['smokeping_config_file' => '/etc/smokeping/config', 'smokeping_poller_id' => 1, 'smokeping_reader_budget' => 90, 'smokeping_uninstall_keep_tables' => true, 'smokeping_lib_dir' => ''],
		[
			smokeping_target('Europe/Paris', ['title' => 'Paris: the "gateway" #1', 'menu' => 'yes', 'slaves' => ['site-b', 'a.b']]),
			smokeping_target('Europe/Rome', ['host' => 'rome.example.net', 'state' => AdminState::Disabled, 'present' => false, 'nomaster' => true, 'slaves' => ['site-b']]),
			smokeping_target('Other/x', ['poller' => 2, 'title' => '007']),
		],
		[new ConfigMapping(1, TargetPath::fromString('Europe/Paris'), 'paris.example.net', "Paris GW & 'co' <1>")]
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
	  smokeping_datadir: relative/path
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
		->and($joined)->toContain('line 6: settings/smokeping_datadir must be an absolute path')
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
		->and($errors)->toContain('2 devices have host name "dup.example.net" and description "D" (ids 50, 51)')
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

	expect($custom->settings['smokeping_mount_path'])->toBe('/data/rra/sp');
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
