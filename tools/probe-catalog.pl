#!/usr/bin/perl
# SPDX-License-Identifier: GPL-2.0-or-later
# SPDX-FileCopyrightText: 2026 Thomas Vincent
#
# Build tooling, not runtime: print the variables of every SmokePing probe
# module installed in this container as JSON. tools/make-probe-catalog.sh
# runs it under each supported SmokePing release and merges the output into
# lib/ProbeCatalog.php.

use strict;
use warnings;

use lib '/usr/share/smokeping', '/usr/share/smokeping/lib';
use JSON::PP ();

require Smokeping;

my $dir;
for (@INC) {
	if (-d "$_/Smokeping/probes") { $dir = "$_/Smokeping/probes"; last; }
}
die "no probe directory\n" unless $dir;

opendir my $dh, $dir or die "$dir: $!\n";
my @names = sort grep { s/\.pm$// && /^[A-Z][-_0-9a-zA-Z]*$/ } readdir $dh;
closedir $dh;

sub regex_source {
	my ($re) = @_;
	return undef unless defined $re;
	return "$re";
}

sub vars {
	my ($h) = @_;
	my %out;
	for my $name (sort keys %$h) {
		next if $name eq '_mandatory';
		my $spec = $h->{$name};
		$out{$name} = {
			re      => regex_source($spec->{_re}),
			error   => $spec->{_re_error},
			default => defined $spec->{_default} ? "$spec->{_default}" : undef,
		};

		# The pings value feeds the plugin's model, so record the range its
		# check accepts; other checks need the SmokePing host.
		if ($name eq 'pings' && ref $spec->{_sub} eq 'CODE') {
			my @accepted = grep { !defined $spec->{_sub}->($_) } (0 .. 100, 1000000);
			my ($refused) = grep { defined $spec->{_sub}->($_) } (0 .. 100, 1000000);
			$out{$name}{range} = [ $accepted[0], ($accepted[-1] == 1000000 ? undef : $accepted[-1]), $spec->{_sub}->($refused) ];
		}
	}
	return \%out;
}

my %catalog;
for my $name (@names) {
	my $class = "Smokeping::probes::$name";
	next unless eval "require $class; 1";
	my $probevars  = $class->probevars;
	my $targetvars = $class->targetvars;
	my $pv = vars($probevars);
	my $tv = vars($targetvars);
	# Smokeping::get_parser() drops a probe variable that is also a target variable.
	delete $pv->{$_} for keys %$tv;
	$catalog{$name} = {
		fork              => $class->isa('Smokeping::probes::basefork') ? JSON::PP::true : JSON::PP::false,
		probe_vars        => $pv,
		probe_mandatory   => [ @{ $probevars->{_mandatory} || [] } ],
		target_vars       => $tv,
		target_mandatory  => [ @{ $targetvars->{_mandatory} || [] } ],
	};
}

print JSON::PP->new->canonical->pretty->encode({ smokeping => "$Smokeping::VERSION", probes => \%catalog });
