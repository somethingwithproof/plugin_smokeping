#!/usr/bin/perl
# SPDX-FileCopyrightText: 2026 Thomas Vincent <thomasvincent@gmail.com>
# SPDX-License-Identifier: GPL-2.0-or-later
#
# This file is part of plugin_smokeping, a plugin for Cacti.
# https://github.com/somethingwithproof/plugin_smokeping
#
# This program is free software; you can redistribute it and/or modify it
# under the terms of the GNU General Public License as published by the Free
# Software Foundation; either version 2 of the License, or (at your option)
# any later version.
#
# This program is distributed in the hope that it will be useful, but WITHOUT
# ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
# FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for
# more details.
#
# Test oracle for the plugin's PHP configuration parser. This is the Perl
# inventory script the plugin ran before it parsed the configuration itself:
# it loads SmokePing's own parser the way smokeinfo (Smokeping::Info) does
# and prints the target inventory as JSON. tests/oracle/run.sh compares its
# output with SmokepingInventory::document() field for field.
#
# usage: smokeping-inventory.pl --config=FILE [--libdir=DIR]
#        smokeping-inventory.pl --list=FILE [--libdir=DIR]
#
# With --list, FILE names one configuration per line and the script prints
# one JSON object per line: {"config":..., "inventory":...} or
# {"config":..., "error":...}.

use strict;
use warnings;

use Getopt::Long qw(GetOptions);
use JSON::PP ();

my ($config, $list, $libdir);

GetOptions('config=s' => \$config, 'list=s' => \$list, 'libdir=s' => \$libdir)
	or die "usage: $0 --config=FILE|--list=FILE [--libdir=DIR]\n";

die "usage: $0 --config=FILE|--list=FILE [--libdir=DIR]\n" unless defined $config or defined $list;

# Packaged locations of Smokeping.pm: Debian and Ubuntu install it in the
# default @INC; EL, Fedora, and Alpine use /usr/share/smokeping.
my @libs = defined $libdir ? ($libdir) : ('/usr/share/smokeping', '/usr/share/smokeping/lib', '/opt/smokeping/lib');
unshift @INC, grep { -d $_ } @libs;

eval { require Smokeping; 1 } or die "ERROR: unable to load Smokeping.pm: $@";

sub int_or_null {
	my ($value) = @_;

	return (defined $value && $value =~ /^\d+$/) ? $value + 0 : undef;
}

sub inventory {
	my ($cfg) = @_;

	my $database = $cfg->{Database} || {};
	my $general  = $cfg->{General}  || {};
	my $probes   = $cfg->{Probes}   || {};

	# Probe properties for a probe or subprobe name, as load_probes() would
	# resolve them, without instantiating the probe module, and the module
	# that runs it.
	my $probe_properties = sub {
		my ($name) = @_;

		return ($probes->{$name}, undef, $name) if ref $probes->{$name} eq 'HASH';

		for my $parent (keys %$probes) {
			next unless ref $probes->{$parent} eq 'HASH';

			my $sub = $probes->{$parent}{$name};

			return ($sub, $probes->{$parent}, $parent) if ref $sub eq 'HASH';
		}

		return ({}, undef, undef);
	};

	# The module's own ProbeDesc() on the properties load_probe() would pass
	# to new(), called on a bare object so the probe's binary and network
	# checks in new() do not run. FTPtransfer reads files on the SmokePing
	# host, so the plugin reports no description for it, and neither does this.
	my $probe_desc = sub {
		my ($module, $properties) = @_;

		return undef if !defined $module || $module eq 'FTPtransfer';
		return undef unless eval "require Smokeping::probes::$module; 1";

		my $self = bless { properties => $properties }, "Smokeping::probes::$module";
		my $desc = eval { local $SIG{__WARN__} = sub {}; $self->ProbeDesc() };

		return defined $desc ? "$desc" : undef;
	};

	# Smokeping::probes::base::step(): a probe step applies unless
	# concurrentprobes is 'no'.
	my $step_for = sub {
		my ($properties, $parent) = @_;

		my $concurrent = !(defined $general->{concurrentprobes} && $general->{concurrentprobes} eq 'no');

		if ($concurrent) {
			return $properties->{step} if defined $properties->{step};
			return $parent->{step} if $parent && defined $parent->{step};
		}

		return $database->{step};
	};

	# Smokeping::probes::basefork::_pings() honours a target-level pings, then
	# the probe's, then the Database section's.
	my $pings_for = sub {
		my ($node, $properties, $parent) = @_;

		return $node->{pings} if defined $node->{pings};
		return $properties->{pings} if defined $properties->{pings};
		return $parent->{pings} if $parent && defined $parent->{pings};

		return $database->{pings};
	};

	my @targets;
	my $walk;

	$walk = sub {
		my ($node, @path) = @_;

		# A node with its own host is a target with an RRD at datadir/path.rrd.
		# host values starting with "/" are multi-host graphs of other targets
		# and have no RRD of their own.
		if (@path && defined $node->{host} && $node->{host} !~ m{^/}) {
			my $probe = $node->{probe} // '';
			my ($properties, $parent, $module) = $probe_properties->($probe);

			push @targets, {
				path         => join('/', @path),
				host         => $node->{host},
				probe        => $probe,
				menu         => $node->{menu} // '',
				title        => $node->{title} // '',
				slaves       => [ split ' ', ($node->{slaves} // '') ],
				nomasterpoll => (defined $node->{nomasterpoll} && $node->{nomasterpoll} eq 'yes') ? JSON::PP::true : JSON::PP::false,
				step         => int_or_null($step_for->($properties, $parent)),
				pings        => int_or_null($pings_for->($node, $properties, $parent)),
				probe_desc   => $probe_desc->($module, $properties),
				alerts       => $node->{alerts} // '',
			};
		}

		# Child sections are the hash-valued keys; Config::Grammar keeps the
		# file order in _order.
		my @children = ref $node->{_order} eq 'ARRAY' ? @{ $node->{_order} } : sort grep { ref $node->{$_} eq 'HASH' } keys %$node;

		for my $child (@children) {
			$walk->($node->{$child}, @path, $child) if ref $node->{$child} eq 'HASH';
		}
	};

	$walk->($cfg->{Targets} || {});

	my $version = defined $Smokeping::VERSION ? "$Smokeping::VERSION" : '';

	return {
		format    => 1,
		smokeping => $version,
		datadir   => $general->{datadir} // '',
		step      => int_or_null($database->{step}),
		pings     => int_or_null($database->{pings}),
		targets   => \@targets,
	};
}

# A fresh parser per file: get_parser() keeps the probes it has seen.
sub parse {
	my ($file) = @_;

	my $parser = Smokeping::get_parser();
	my $cfg    = eval { $parser->parse($file) };

	return (undef, "ERROR: $@") if $@;
	return (undef, "ERROR: $parser->{err}") unless $cfg;

	return (inventory($cfg), undef);
}

my $json = JSON::PP->new->canonical;

if (defined $config) {
	my ($inventory, $error) = parse($config);

	die "$error\n" if defined $error;

	print $json->encode($inventory), "\n";

	exit 0;
}

open my $fh, '<', $list or die "ERROR: $list: $!\n";

while (my $file = <$fh>) {
	chomp $file;
	next if $file eq '';

	my ($inventory, $error) = parse($file);
	my $error_line = defined $error ? $error : undef;
	$error_line =~ s/\s+\z// if defined $error_line;

	print $json->encode(defined $error ? { config => $file, error => $error_line } : { config => $file, inventory => $inventory }), "\n";
}
