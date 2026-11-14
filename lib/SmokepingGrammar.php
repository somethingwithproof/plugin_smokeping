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

namespace Cacti\Smokeping;

/**
 * SmokePing's configuration grammar, as Smokeping::get_parser() defines it
 * for Config::Grammar::Dynamic in SmokePing 2.7.3 to 2.9.0.
 *
 * Section and variable names, mandatory lists, inheritance, defaults, value
 * patterns and their error texts are SmokePing's. Checks that need the
 * SmokePing host (a directory or file exists, a template is readable, a
 * probe binary is executable, a strftime format evaluates, a hostname
 * resolves) are not repeated: the plugin reads the file on the Cacti side and
 * SmokePing has already refused a configuration that fails them.
 *
 * Probe sections and target sections change their grammar with the probe
 * they name; SmokepingConfigParser builds those from ProbeCatalog.
 */
final class SmokepingGrammar {
	public const ROOT          = 'root';
	public const GENERAL       = 'General';
	public const DATABASE      = 'Database';
	public const INFLUXDB      = 'InfluxDB';
	public const PRESENTATION  = 'Presentation';
	public const CHARTS        = 'Presentation/charts';
	public const CHART         = 'Presentation/charts/*';
	public const OVERVIEW      = 'Presentation/overview';
	public const DETAIL        = 'Presentation/detail';
	public const LOSS_COLORS   = 'Presentation/detail/loss_colors';
	public const UPTIME_COLORS = 'Presentation/detail/uptime_colors';
	public const MULTIHOST     = 'Presentation/multihost';
	public const HIERARCHIES   = 'Presentation/hierarchies';
	public const HIERARCHY     = 'Presentation/hierarchies/*';
	public const PROBES        = 'Probes';
	public const PROBE         = 'Probes/*';
	public const SUBPROBE      = 'Probes/*/*';
	public const ALERTS        = 'Alerts';
	public const ALERT         = 'Alerts/*';
	public const SLAVES        = 'Slaves';
	public const SLAVE         = 'Slaves/*';
	public const OVERRIDE      = 'Slaves/*/override';
	public const TARGETS       = 'Targets';
	public const TARGET        = 'Targets/*';

	public const KEYD_RE  = '[-_0-9a-zA-Z]+';
	public const KEYDD_RE = '[-_0-9a-zA-Z.]+';

	/** Target variables every probe accepts, in SmokePing's order. */
	public const TARGET_COMMON_VARS = ['probe', 'menu', 'title', 'alerts', 'note', 'email', 'host', 'remark', 'rawlog', 'alertee', 'slaves', 'menuextra', 'parents', 'hide', 'nomasterpoll'];

	/** Target variables a target section takes from its parent. */
	public const TARGET_INHERITED = ['probe', 'alerts', 'alertee', 'slaves', 'menuextra', 'nomasterpoll'];

	private const YES_NO = ['re' => '(yes|no)', 'error' => "this must either be 'yes' or 'no'"];
	private const COLOR  = ['re' => '[0-9a-f]{6}', 'error' => 'use rrggbb for color'];
	private const INT1   = ['check' => 'int1'];

	/**
	 * A variable's spec: re and error as in the grammar, an optional named
	 * check for a _sub that needs no SmokePing host, and an optional default.
	 * A null vars list is a section that takes no variables at all, which
	 * Config::Grammar reports differently from an unknown variable.
	 *
	 * @return array{vars: ?array<string, array{re?: ?string, error?: ?string, check?: string, default?: ?string}>, sections?: array<string, string>, mandatory?: list<string>, inherited?: list<string>, table?: array{columns: int, cols: array<int, array{re?: string, error?: string, check?: string}>}}
	 */
	public static function section(string $kind): array {
		$keyd = '/' . self::KEYD_RE . '/';

		return match ($kind) {
			self::ROOT => [
				'vars'      => null,
				'sections'  => ['General' => self::GENERAL, 'Database' => self::DATABASE, 'Presentation' => self::PRESENTATION, 'Probes' => self::PROBES, 'Targets' => self::TARGETS, 'Alerts' => self::ALERTS, 'Slaves' => self::SLAVES, 'InfluxDB' => self::INFLUXDB],
				'mandatory' => ['General', 'Database', 'Presentation', 'Probes', 'Targets'],
			],
			self::GENERAL => [
				'vars' => [
					'owner'                => [],
					'imgcache'             => [],
					'imgurl'               => [],
					'datadir'              => [],
					'dyndir'               => [],
					'pagedir'              => [],
					'piddir'               => [],
					'sendmail'             => [],
					'offset'               => ['re' => '(\d+%|random)', 'error' => "Use offset either in % of operation interval or 'random'"],
					'smokemail'            => [],
					'cgiurl'               => ['re' => 'https?://\S+', 'error' => 'cgiurl must be a http(s)://.... url'],
					'mailhost'             => [],
					'mailuser'             => [],
					'mailpass'             => [],
					'snpphost'             => [],
					'contact'              => ['re' => '\S+@\S+', 'error' => "use an email address of the form 'name@place.dom'"],
					'display_name'         => [],
					'syslogfacility'       => ['re' => '\w+', 'error' => 'syslogfacility must be alphanumeric'],
					'syslogpriority'       => ['re' => '\w+', 'error' => 'syslogpriority must be alphanumeric'],
					'concurrentprobes'     => self::YES_NO,
					'changeprocessnames'   => self::YES_NO + ['default' => 'yes'],
					'tmail'                => [],
					'changecgiprogramname' => self::YES_NO + ['default' => 'yes'],
					'linkstyle'            => ['re' => '(?:absolute|relative|original)', 'error' => 'linkstyle must be one of "absolute", "relative" or "original"', 'default' => 'relative'],
					'precreateperms'       => ['re' => '[0-7]+', 'error' => 'please specify the permissions in octal'],
				],
				'mandatory' => ['owner', 'imgcache', 'imgurl', 'datadir', 'piddir', 'smokemail', 'cgiurl', 'contact'],
			],
			self::DATABASE => [
				'vars' => [
					'step'  => self::INT1,
					'pings' => ['re' => '\d+', 'check' => 'pings3'],
				],
				'mandatory' => ['step', 'pings'],
				'table'     => ['columns' => 4, 'cols' => [
					0 => ['re' => '(AVERAGE|MIN|MAX)', 'error' => 'Choose a valid consolidation function'],
					1 => ['check' => 'xff'],
					2 => self::INT1,
					3 => self::INT1,
				]],
			],
			self::INFLUXDB => [
				'vars' => [
					'host'     => ['re' => '\S+'],
					'port'     => ['re' => '\d+', 'check' => 'influxport', 'default' => '8086'],
					'timeout'  => self::INT1 + ['default' => '15'],
					'database' => ['re' => '\S+'],
					'username' => ['re' => '\S+'],
					'password' => ['re' => '\S+'],
				],
				'mandatory' => ['host', 'database'],
			],
			self::PRESENTATION => [
				'vars' => [
					'template'        => [],
					'charset'         => [],
					'htmltitle'       => self::YES_NO,
					'graphborders'    => self::YES_NO,
					'literalsearch'   => self::YES_NO,
					'colortext'       => self::COLOR,
					'colorbackground' => self::COLOR,
					'colorborder'     => self::COLOR,
				],
				'sections'  => ['overview' => self::OVERVIEW, 'detail' => self::DETAIL, 'charts' => self::CHARTS, 'multihost' => self::MULTIHOST, 'hierarchies' => self::HIERARCHIES],
				'mandatory' => ['overview', 'template', 'detail'],
			],
			self::CHARTS => [
				'vars'      => ['menu' => [], 'title' => []],
				'sections'  => [$keyd => self::CHART],
				'mandatory' => ['menu', 'title'],
			],
			self::CHART => [
				'vars'      => ['menu' => [], 'title' => [], 'sorter' => ['re' => '\S+\(\S+\)', 'error' => 'use a sorter call here: Sorter(arg1=>val1,arg2=>val2)'], 'format' => []],
				'mandatory' => ['menu', 'title', 'sorter'],
			],
			self::OVERVIEW => [
				'vars' => [
					'width'        => ['check' => 'width'],
					'height'       => ['check' => 'height'],
					'range'        => ['re' => '\d+[smhdwy]', 'error' => 'graph range must be a number followed by [smhdwy]'],
					'max_rtt'      => [],
					'median_color' => self::COLOR,
					'strftime'     => [],
				],
				'mandatory' => ['width', 'height'],
			],
			self::DETAIL => [
				'vars' => [
					'width'            => ['check' => 'width'],
					'height'           => ['check' => 'height'],
					'loss_background'  => self::YES_NO,
					'logarithmic'      => self::YES_NO,
					'unison_tolerance' => ['check' => 'tolerance'],
					'max_rtt'          => [],
					'strftime'         => [],
					'nodata_color'     => ['re' => '[0-9a-f]{6}', 'error' => 'color must be defined with in rrggbb syntax'],
				],
				'sections'  => ['loss_colors' => self::LOSS_COLORS, 'uptime_colors' => self::UPTIME_COLORS],
				'mandatory' => ['width', 'height'],
				'table'     => ['columns' => 2, 'cols' => [0 => [], 1 => ['re' => '\d+[smhdwy]', 'error' => 'graph age must be a number followed by [smhdwy]']]],
			],
			self::LOSS_COLORS => [
				'vars'  => null,
				'table' => ['columns' => 3, 'cols' => [
					0 => ['re' => '\d+.?\d*', 'error' => 'I was expecting a number'],
					1 => ['re' => '[0-9a-f]+', 'error' => 'I was expecting a color of the form rrggbb'],
					2 => [],
				]],
			],
			self::UPTIME_COLORS => [
				'vars'  => null,
				'table' => ['columns' => 3, 'cols' => [
					0 => ['re' => '\d+.?\d*', 'error' => 'I was expecting a number'],
					1 => ['re' => '[0-9a-f]{6}', 'error' => 'I was expecting a color of the form rrggbb'],
					2 => [],
				]],
			],
			self::MULTIHOST => [
				'vars' => ['colors' => ['re' => '[0-9a-z]{6}(?: [0-9a-z]{6})*']],
			],
			self::HIERARCHIES => [
				'vars'     => null,
				'sections' => [$keyd => self::HIERARCHY],
			],
			self::HIERARCHY => [
				'vars'      => ['title' => []],
				'mandatory' => ['title'],
			],
			self::PROBES => [
				'vars'     => null,
				'sections' => [$keyd => self::PROBE],
			],
			self::ALERTS => [
				'vars' => [
					'to'           => ['re' => '(\|.+|.+@\S+|snpp:|xmpp:)', 'error' => 'put an email address or the name of a program here'],
					'from'         => ['re' => '.+@\S+', 'error' => 'put an email address here'],
					'edgetrigger'  => self::YES_NO + ['default' => 'no'],
					'mailtemplate' => [],
				],
				'sections'  => ['/[^\s,]+/' => self::ALERT],
				'mandatory' => ['to', 'from'],
			],
			self::ALERT => [
				'vars' => [
					'type'         => ['re' => '(rtt|loss|matcher)', 'error' => 'Use loss, rtt or matcher'],
					'pattern'      => ['re' => '(?:([^,]+)(,[^,]+)*|\S+\(.+\s)', 'error' => 'Could not parse pattern or matcher'],
					'comment'      => [],
					'to'           => ['re' => '(\|.+|.+@\S+|snpp:|xmpp:)', 'error' => 'put an email address or the name of a program here'],
					'edgetrigger'  => self::YES_NO + ['default' => 'no'],
					'mailtemplate' => [],
					'priority'     => ['re' => '[1-9]\d*', 'error' => 'priority must be between 1 and oo'],
				],
				'inherited' => ['edgetrigger', 'mailtemplate'],
				'mandatory' => ['type', 'pattern', 'comment'],
			],
			self::SLAVES => [
				'vars'      => ['secrets' => []],
				'sections'  => ['/' . self::KEYDD_RE . '/' => self::SLAVE],
				'mandatory' => ['secrets'],
			],
			self::SLAVE => [
				'vars'      => ['display_name' => [], 'location' => [], 'color' => ['re' => '[0-9a-f]{6}', 'error' => 'I was expecting a color of the form rrggbb']],
				'sections'  => ['override' => self::OVERRIDE],
				'mandatory' => ['display_name', 'color'],
			],
			self::OVERRIDE => [
				'vars' => ['/\S+/' => []],
			],
			self::TARGETS => [
				'vars' => [
					'probe'     => ['check' => 'probe'],
					'menu'      => [],
					'title'     => [],
					'remark'    => [],
					'alerts'    => [],
					'slaves'    => [],
					'menuextra' => [],
					'parents'   => [],
				],
				'mandatory' => ['probe', 'menu', 'title'],
			],
			default => throw new \LogicException('No fixed grammar for ' . $kind),
		};
	}

	/**
	 * Specs of the variables every target section accepts.
	 *
	 * @return array<string, array{re?: ?string, error?: ?string, check?: string, default?: ?string}>
	 */
	public static function targetCommon(): array {
		$keyd  = self::KEYD_RE;
		$keydd = self::KEYDD_RE;

		return [
			'probe'        => ['check' => 'probe_target'],
			'menu'         => [],
			'title'        => [],
			'alerts'       => ['re' => '([^\s,]+(,[^\s,]+)*)?', 'error' => 'Comma separated list of alert names'],
			'note'         => [],
			'email'        => ['re' => '.+\s<\S+@\S+>', 'error' => "use an email address of the form 'First Last <em@ail.kg>'"],
			'host'         => [],
			'remark'       => [],
			'rawlog'       => [],
			'alertee'      => ['re' => '^(?:\|.+|.+@\S+|snpp:.+|xmpp:.+)(?:\s*,\s*(?:\|.+|.+@\S+|snpp:.+|xmpp:.+))*$', 'error' => 'the alertee must be an email address here'],
			'slaves'       => ['re' => "({$keydd}(?:\\s+{$keydd})*)?", 'error' => "Use the format: slaves={$keydd} [slave2]"],
			'menuextra'    => [],
			'parents'      => ['re' => "{$keyd}:/(?:{$keyd}(?:/{$keyd})*)?(?: {$keyd}:/(?:{$keyd}(?:/{$keyd})*)?)*", 'error' => 'Use hierarchy:/parent/path syntax'],
			'hide'         => ['re' => '(yes|no)', 'default' => 'no'],
			'nomasterpoll' => ['re' => '(yes|no)', 'default' => 'no'],
		];
	}
}
