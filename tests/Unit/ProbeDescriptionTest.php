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

use Somethingwithproof\Smokeping\ProbeDescription;
use Somethingwithproof\Smokeping\SmokepingSection;

/**
 * SmokePing's probe descriptions. The expected strings are each probe's
 * ProbeDesc() in SmokePing 2.9.0; tests/oracle compares the port with the
 * method itself over generated configurations.
 */

describe('ProbeDescription::of', function () {
	it('describes each probe of a configuration as SmokePing does', function () {
		$probes = "+ FPing\nbinary = /usr/bin/fping\n++ Small\n++ Big\npacketsize = 1000\n"
			. "+ FPing6\nbinary = /usr/bin/fping6\n"
			. "+ DNS\nbinary = /usr/bin/dig\n"
			. "+ TCPPing\nbinary = /usr/bin/tcpping\n"
			. "+ Curl\nbinary = /usr/bin/curl\nurlformat = http://%host%/\n";
		$targets = "probe = Small\nmenu = m\ntitle = t\n+ a\nhost = h1\n+ b\nprobe = Big\nhost = h2\n+ c\nprobe = FPing6\nhost = h3\n+ d\nprobe = DNS\nhost = h4\n+ e\nprobe = TCPPing\nhost = h5\n+ f\nprobe = Curl\nhost = h6\n";
		$t = sp_targets(sp_config($targets, $probes));

		expect(array_map(fn (array $target) => $target['probe_desc'], $t))->toBe([
			'a' => 'ICMP Echo Pings (56 Bytes)',
			'b' => 'ICMP Echo Pings (1000 Bytes)',
			'c' => 'IPv6-ICMP Echo Pings (56 Bytes)',
			'd' => 'DNS requests',
			'e' => 'TCP Pings',
			'f' => 'URLs using curl(1)',
		]);
	});

	it('names the packet size, game, or tos each probe reads', function () {
		$section = new SmokepingSection('p', 'probe', 'Probes/p', 1);
		$section->vars = ['packetsize' => '100', 'game' => 'q2s', 'tos' => '0x10'];

		expect(array_map(fn (string $module) => ProbeDescription::of($module, $section), ['FPing6', 'RemoteFPing', 'DismanPing', 'IOSPing', 'TelnetIOSPing', 'TelnetJunOSPing', 'OpenSSHEOSPing', 'OpenSSHJunOSPing', 'Qstat', 'SSH']))->toBe([
			'IPv6-ICMP Echo Pings (100 Bytes)',
			'Remote ICMP Echo Pings (100 Bytes)',
			'DISMAN ICMP Echo Pings (100 Bytes)',
			'Cisco IOS - ICMP Echo Pings (100 Bytes)',
			'InfoAve Cisco IOS - ICMP Echo Pings (100 Bytes)',
			'InfoAve Juniper JunOS - ICMP Echo Pings (100 Bytes)',
			'Arista EOS - ICMP Echo Pings (100 Bytes)',
			' tos 0x10)',
			'Game server q2s pings',
			'SSH requests',
		]);

		unset($section->vars['tos']);

		expect(ProbeDescription::of('OpenSSHJunOSPing', $section))->toBe('Juniper JunOS - ICMP Echo Pings (100 Bytes)');
	});

	it('reports no description for a probe it cannot describe', function () {
		expect(ProbeDescription::of('FTPtransfer', null))->toBeNull()
			->and(ProbeDescription::of('NoSuchProbe', null))->toBeNull();
	});

	it('keeps the quirks of SmokePing\'s own methods', function () {
		// Perl's "|| default" takes an empty value as missing, and the JunOS
		// probe replaces its text with the tos value instead of appending it.
		expect(ProbeDescription::of('Qstat', null))->toBe('Game server q3s pings')
			->and(ProbeDescription::of('FPing', null))->toBe('ICMP Echo Pings (56 Bytes)')
			->and(ProbeDescription::of('TraceroutePing', null))->toBe('Traceroute (UDP + TTL)  Pings');
	});
});

describe('ProbeDescription::line', function () {
	it('prints SmokePing\'s probe line', function () {
		expect(ProbeDescription::line(20, 'ICMP Echo Pings (56 Bytes)', 'FPing', 300))->toBe('probe:       20 ICMP Echo Pings (56 Bytes) every 300s')
			->and(ProbeDescription::line(5, '', 'FTPtransfer', 60))->toBe('probe:       5 FTPtransfer every 60s');
	});

	it('leaves out what Cacti would substitute or cannot store', function () {
		$line = ProbeDescription::line(20, 'Game server |host_snmp_community|\\g pings', 'Qstat', 300);

		expect($line)->toBe('probe:       20 Game server host_snmp_communityg pings every 300s')
			->and(mb_strlen(ProbeDescription::line(20, str_repeat('x', 400), 'Qstat', 300)))->toBe(255);
	});
});
