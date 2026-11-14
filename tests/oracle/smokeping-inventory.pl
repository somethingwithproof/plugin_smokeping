#!/usr/bin/perl
# SPDX-License-Identifier: GPL-2.0-or-later
# SPDX-FileCopyrightText: 2026 Thomas Vincent
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
	# resolve them, without instantiating the probe module.
	my $probe_properties = sub {
		my ($name) = @_;

		return ($probes->{$name}, undef) if ref $probes->{$name} eq 'HASH';

		for my $parent (keys %$probes) {
			next unless ref $probes->{$parent} eq 'HASH';

			my $sub = $probes->{$parent}{$name};

			return ($sub, $probes->{$parent}) if ref $sub eq 'HASH';
		}

		return ({}, undef);
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
			my ($properties, $parent) = $probe_properties->($probe);

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
