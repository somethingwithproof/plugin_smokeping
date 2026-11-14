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

# Print the fixture time ranges and SmokePing's graph maximum for each:
#   <pings> <range> <start> <end> <max>
# The maximum follows Smokeping.pm: 1.5 x the largest median over the range,
# with an unknown maximum counted as 1.
#
#   ranges.pl <rrdtool> <fixture-directory>
use strict;
use warnings;

my ($rrdtool, $dir) = @ARGV;
die "usage: ranges.pl rrdtool dir\n" unless defined $dir;

my $t0      = 1700000100;
my $step    = 300;
my $segment = 36;

my @ranges = (['all', $t0, $t0 + 4 * $segment * $step]);
push @ranges, ["segment$_", $t0 + $_ * $segment * $step, $t0 + ($_ + 1) * $segment * $step] for 0 .. 3;

for my $pings (10, 20, 30) {
	for my $r (@ranges) {
		my ($name, $start, $end) = @$r;
		my $out = `$rrdtool graph /dev/null --start $start --end $end DEF:maxping=$dir/p$pings.rrd:median:AVERAGE PRINT:maxping:MAX:%le 2>&1`;
		my ($value) = $out =~ /^([-+0-9.]+e[-+]\d+|-?nan)$/mi;
		$value = 1 if !defined $value || $value =~ /nan/i;
		printf "%d %s %d %d %.15g\n", $pings, $name, $start, $end, $value * 1.5;
	}
}
