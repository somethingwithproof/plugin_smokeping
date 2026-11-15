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
 * The probe description SmokePing prints in a graph's probe line, "20 ICMP
 * Echo Pings (56 Bytes) every 300s": each probe module's ProbeDesc(), ported
 * from SmokePing 2.9.0. It reads the probe section's own properties, with the
 * inherited and default values the parser fills in, as ProbeDesc reads
 * $self->{properties}. tests/oracle checks it against SmokePing's own method.
 */
final class ProbeDescription {
	/** ProbeDesc() strings that read no property. */
	private const FIXED = [
		'AnotherCurl'           => 'URLs using curl(1)',
		'AnotherDNS'            => 'DNS requests',
		'AnotherSSH'            => 'SSH connections',
		'CiscoRTTMonDNS'        => 'CiscoRTTMonDNS.pm',
		'CiscoRTTMonEchoICMP'   => 'CiscoRTTMonEchoICMP',
		'CiscoRTTMonTcpConnect' => 'CiscoRTTMonTcpConnect',
		'Curl'                  => 'URLs using curl(1)',
		'DNS'                   => 'DNS requests',
		'EchoPing'              => 'TCP or UDP Echo pings using echoping(1)',
		'EchoPingChargen'       => 'TCP Chargen pings using echoping(1)',
		'EchoPingDiscard'       => 'TCP or UDP Discard pings using echoping(1)',
		'EchoPingDNS'           => 'DNS pings using the echoping_dns plugin',
		'EchoPingHttp'          => 'HTTP pings using echoping(1)',
		'EchoPingHttps'         => 'HTTPS pings using echoping(1)',
		'EchoPingIcp'           => 'ICP pings using echoping(1)',
		'EchoPingLDAP'          => 'LDAP pings using the echoping_ldap plugin',
		'EchoPingPlugin'        => 'Pings using an echoping(1) plugin',
		'EchoPingSmtp'          => 'SMTP pings using echoping(1)',
		'EchoPingWhois'         => 'whois pings using the echoping_whois plugin',
		'IRTT'                  => 'IRTT round-trips',
		'LDAP'                  => 'LDAP queries',
		'NFSping'               => 'NFSping',
		'Radius'                => 'RADIUS queries',
		'SendEmail'             => 'Measure time to send a complete email',
		'SipSak'                => 'SIP OPTIONS messages',
		'SSH'                   => 'SSH requests',
		'TacacsPlus'            => 'TacacsPlus Authentication Attempts',
		'TCPPing'               => 'TCP Pings',
		'TraceroutePing'        => 'Traceroute (UDP + TTL)  Pings',
		'WebProxyFilter'        => 'HTTP GETs',
	];

	/**
	 * The description for a probe module and its section, or null when the
	 * plugin cannot know it: a module SmokePing 2.9.0 does not ship, or
	 * FTPtransfer, whose description is the size of a file on the SmokePing
	 * host.
	 */
	public static function of(string $module, ?SmokepingSection $properties): ?string {
		$value = static fn (string $name): string => (string) ($properties?->value($name) ?? '');

		// Perl's "|| 56": an empty or zero value takes the default too.
		$bytes = static function () use ($value): string {
			$size = $value('packetsize');

			return $size === '' || $size === '0' ? '56' : $size;
		};

		if (isset(self::FIXED[$module])) {
			return self::FIXED[$module];
		}

		return match ($module) {
			'FPing', 'FPingContinuous' => 'ICMP Echo Pings (' . $bytes() . ' Bytes)',
			'FPing6'                   => 'IPv6-ICMP Echo Pings (' . $bytes() . ' Bytes)',
			'RemoteFPing'              => 'Remote ICMP Echo Pings (' . $bytes() . ' Bytes)',
			'DismanPing'               => 'DISMAN ICMP Echo Pings (' . $bytes() . ' Bytes)',
			'IOSPing'                  => 'Cisco IOS - ICMP Echo Pings (' . $value('packetsize') . ' Bytes)',
			'TelnetIOSPing'            => 'InfoAve Cisco IOS - ICMP Echo Pings (' . $value('packetsize') . ' Bytes)',
			'TelnetJunOSPing'          => 'InfoAve Juniper JunOS - ICMP Echo Pings (' . $value('packetsize') . ' Bytes)',
			'OpenSSHEOSPing'           => 'Arista EOS - ICMP Echo Pings (' . $value('packetsize') . ' Bytes)',
			// SmokePing replaces the whole string when tos is set, not appends.
			'OpenSSHJunOSPing'         => ($properties?->value('tos') !== null ? ' tos ' . $value('tos') : 'Juniper JunOS - ICMP Echo Pings (' . $value('packetsize') . ' Bytes') . ')',
			'Qstat'                    => 'Game server ' . ($value('game') === '' || $value('game') === '0' ? 'q3s' : $value('game')) . ' pings',
			default                    => null,
		};
	}

	/** The text of the template's probe line item, which the plugin replaces. */
	public const PLACEHOLDER = 'probe:';

	/**
	 * SmokePing's probe line, with its spacing: "probe:       <pings>
	 * <description> every <step>s", with the probe's name in place of a
	 * description the plugin cannot know (''). Cacti substitutes |host_*|
	 * and |query_*| fields in graph text, and rrdtool reads "\\l" and the
	 * like in a COMMENT as layout; the description carries values from the
	 * SmokePing configuration, so "|" and "\\" are dropped. No ProbeDesc()
	 * prints either. graph_templates_item.text_format holds 255 characters.
	 */
	public static function line(int $pings, string $description, string $probe, int $step): string {
		$text = sprintf('%s       %d %s every %ds', self::PLACEHOLDER, $pings, $description !== '' ? $description : $probe, $step);
		$text = (string) preg_replace('/[|\\\\\x00-\x1f\x7f]/u', '', $text);

		return mb_substr($text, 0, 255);
	}
}
