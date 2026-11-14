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
# Print the SmokePing target inventory as JSON, using SmokePing's own parser
# the way smokeinfo (Smokeping::Info) does. Reads the configuration only; it
# never loads probes, starts the daemon, or writes anything.
#
# usage: smokeping-inventory.pl --config=FILE [--libdir=DIR]

use strict;
use warnings;

use Getopt::Long qw(GetOptions);
use JSON::PP ();

my ($config, $libdir);

GetOptions('config=s' => \$config, 'libdir=s' => \$libdir)
	or die "usage: $0 --config=FILE [--libdir=DIR]\n";

die "usage: $0 --config=FILE [--libdir=DIR]\n" unless defined $config;

# Packaged locations of Smokeping.pm: Debian and Ubuntu install it in the
# default @INC; EL, Fedora, and Alpine use /usr/share/smokeping.
my @libs = defined $libdir ? ($libdir) : ('/usr/share/smokeping', '/usr/share/smokeping/lib', '/opt/smokeping/lib');
unshift @INC, grep { -d $_ } @libs;

eval { require Smokeping; 1 } or die "ERROR: unable to load Smokeping.pm: $@";

my $parser = Smokeping::get_parser();
my $cfg    = $parser->parse($config) or die "ERROR: $parser->{err}\n";

my $database = $cfg->{Database} || {};
my $general  = $cfg->{General}  || {};
my $probes   = $cfg->{Probes}   || {};

# Probe properties for a probe or subprobe name, as load_probes() would
# resolve them, without instantiating the probe module.
sub probe_properties {
	my ($name) = @_;

	return ($probes->{$name}, undef) if ref $probes->{$name} eq 'HASH';

	for my $parent (keys %$probes) {
		next unless ref $probes->{$parent} eq 'HASH';

		my $sub = $probes->{$parent}{$name};

		return ($sub, $probes->{$parent}) if ref $sub eq 'HASH';
	}

	return ({}, undef);
}

# Smokeping::probes::base::step(): a probe step applies unless
# concurrentprobes is 'no'.
sub step_for {
	my ($properties, $parent) = @_;

	my $concurrent = !(defined $general->{concurrentprobes} && $general->{concurrentprobes} eq 'no');

	if ($concurrent) {
		return $properties->{step} if defined $properties->{step};
		return $parent->{step} if $parent && defined $parent->{step};
	}

	return $database->{step};
}

# Smokeping::probes::basefork::_pings() honours a target-level pings, then
# the probe's, then the Database section's.
sub pings_for {
	my ($node, $properties, $parent) = @_;

	return $node->{pings} if defined $node->{pings};
	return $properties->{pings} if defined $properties->{pings};
	return $parent->{pings} if $parent && defined $parent->{pings};

	return $database->{pings};
}

sub int_or_null {
	my ($value) = @_;

	return (defined $value && $value =~ /^\d+$/) ? $value + 0 : undef;
}

my @targets;

sub walk {
	my ($node, @path) = @_;

	# A node with its own host is a target with an RRD at datadir/path.rrd.
	# host values starting with "/" are multi-host graphs of other targets
	# and have no RRD of their own.
	if (@path && defined $node->{host} && $node->{host} !~ m{^/}) {
		my $probe = $node->{probe} // '';
		my ($properties, $parent) = probe_properties($probe);

		push @targets, {
			path         => join('/', @path),
			host         => $node->{host},
			probe        => $probe,
			menu         => $node->{menu} // '',
			title        => $node->{title} // '',
			slaves       => [ split ' ', ($node->{slaves} // '') ],
			nomasterpoll => (defined $node->{nomasterpoll} && $node->{nomasterpoll} eq 'yes') ? JSON::PP::true : JSON::PP::false,
			step         => int_or_null(step_for($properties, $parent)),
			pings        => int_or_null(pings_for($node, $properties, $parent)),
		};
	}

	# Child sections are the hash-valued keys; Config::Grammar keeps the
	# file order in _order.
	my @children = ref $node->{_order} eq 'ARRAY' ? @{ $node->{_order} } : sort grep { ref $node->{$_} eq 'HASH' } keys %$node;

	for my $child (@children) {
		walk($node->{$child}, @path, $child) if ref $node->{$child} eq 'HASH';
	}
}

walk($cfg->{Targets} || {});

my $version = defined $Smokeping::VERSION ? "$Smokeping::VERSION" : '';

print JSON::PP->new->canonical->encode({
	format    => 1,
	smokeping => $version,
	datadir   => $general->{datadir} // '',
	step      => int_or_null($database->{step}),
	pings     => int_or_null($database->{pings}),
	targets   => \@targets,
}), "\n";
