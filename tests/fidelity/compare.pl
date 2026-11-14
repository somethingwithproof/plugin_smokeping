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

# Compare two rrdtool graph definitions of the same fixture RRD: SmokePing's
# and a Cacti graph template's. Both inputs are argument lists, one per line.
#
#   compare.pl <rrdtool> <label> <cacti.args> <smokeping.args> <start> <end> <max> <height> <width>
#
# Checks, each printed as one RESULT line:
#   drawing   the ordered drawing items (kind, stacking, color) are equal
#   xport     every drawn series is equal in `rrdtool xport` output
#   print     median and loss average, maximum, minimum, last are equal
#   png       --only-graph renders of the two definitions are byte-identical
# Series are compared as numbers; the largest relative difference is reported.
use strict;
use warnings;
use File::Temp qw(tempfile);
use Digest::MD5 qw(md5_hex);

my ($rrdtool, $label, $cacti_file, $sp_file, $start, $end, $max, $height, $width) = @ARGV;
die "usage\n" unless defined $width;

sub slurp_args {
	my ($file) = @_;
	open my $fh, '<', $file or die "$file: $!";
	chomp(my @lines = <$fh>);
	close $fh;
	return @lines;
}

# Split an argument list into definitions, drawing items, and print items.
sub parse {
	my @args = @_;
	my (@defs, @draw, @print, %vdef);

	for my $arg (@args) {
		if ($arg =~ /^(DEF|CDEF):/) {
			push @defs, $arg;
		} elsif ($arg =~ /^VDEF:([^=]+)=/) {
			$vdef{$1} = 1;
			push @defs, $arg;
		} elsif ($arg =~ /^(AREA|STACK|LINE\d*(?:\.\d+)?|HRULE|VRULE):/) {
			push @draw, $arg;
		} elsif ($arg =~ /^GPRINT:/) {
			push @print, $arg;
		}
	}

	return (\@defs, \@draw, \@print, \%vdef);
}

# Reduce a drawing item to (kind, series, color); the legend text is dropped.
# SmokePing's legacy STACK:name and Cacti's AREA:name::STACK are the same item.
sub normalize_draw {
	my ($item) = @_;
	my $stack = 0;

	$stack = 1 if $item =~ s/^STACK:/AREA:/ || $item =~ s/:STACK$//;
	$item =~ s/::?$//;

	my ($kind, $rest) = split /:/, $item, 2;
	$rest //= '';
	my ($series, $color) = $rest =~ /^([^#:]*)(#[0-9a-fA-F]+)?/;
	$color = defined $color ? uc $color : '';
	$color =~ s/^(#[0-9A-F]{6})FF$/$1/;

	return { kind => $kind, stack => $stack, series => $series, color => $color };
}

sub run {
	my (@cmd) = @_;
	my ($fh, $out) = tempfile(UNLINK => 1);
	close $fh;
	my $pid = fork() // die;

	if ($pid == 0) {
		open STDOUT, '>', $out or die;
		open STDERR, '>&', \*STDOUT or die;
		exec @cmd or die;
	}

	waitpid $pid, 0;
	open my $in, '<', $out or die;
	local $/;
	my $text = <$in>;
	close $in;

	return ($? >> 8, $text);
}

my $failed = 0;

sub result {
	my ($status, $name, $detail) = @_;
	$failed = 1 if $status eq 'FAIL';
	printf "RESULT %s %s %s%s\n", $status, $label, $name, defined $detail && $detail ne '' ? " ($detail)" : '';
}

my @cacti = slurp_args($cacti_file);
my @sp    = slurp_args($sp_file);

my @sides = (
	{ name => 'cacti', parsed => [parse(@cacti)] },
	{ name => 'smokeping', parsed => [parse(@sp)] },
);

for my $side (@sides) {
	$side->{draw} = [ map { normalize_draw($_) } @{ $side->{parsed}[1] } ];
}

# drawing: ordered kind, stacking, color; the series are compared by xport below.
{
	my ($c, $s) = map { $_->{draw} } @sides;
	my $detail = '';
	my $ok     = @$c == @$s;
	$detail    = sprintf('%d vs %d items', scalar @$c, scalar @$s) unless $ok;

	if ($ok) {
		for my $i (0 .. $#$c) {
			for my $k (qw(kind stack color)) {
				next if $c->[$i]{$k} eq $s->[$i]{$k};
				$ok     = 0;
				$detail = "item $i $k: cacti '$c->[$i]{$k}' smokeping '$s->[$i]{$k}'";
				last;
			}
			last unless $ok;
		}
	}

	result($ok ? 'PASS' : 'FAIL', 'drawing', $ok ? scalar(@$c) . ' items' : $detail);
}

# xport: one XPORT per drawn series that has a data series (HRULE has none).
my @xports;

for my $side (@sides) {
	my ($defs) = @{ $side->{parsed} };
	my @cmd = ($rrdtool, 'xport', '--start', $start, '--end', $end, '--step', 300, @$defs);
	my $n   = 0;

	for my $d (@{ $side->{draw} }) {
		next unless $d->{series} =~ /^[A-Za-z][A-Za-z0-9]*$/;
		push @cmd, sprintf('XPORT:%s:i%d', $d->{series}, $n++);
	}

	my ($status, $text) = run(@cmd);

	if ($status != 0 || $text !~ /<data>/) {
		result('FAIL', 'xport', "$side->{name}: " . substr($text, 0, 200));
		$side->{rows} = undef;
		next;
	}

	my @rows;

	# Older rrdtool writes only <v> per row, newer versions add <t>; the first
	# column is the row's own start so that the grid itself is compared.
	my ($first) = $text =~ m{<start>(\d+)</start>};
	my $i = 0;

	while ($text =~ m{<row>(.*?)</row>}gs) {
		my @v = $1 =~ m{<v>([^<]*)</v>}g;
		push @rows, [ $first + $i++ * 300, @v ];
	}

	$side->{rows} = \@rows;
}

if ($sides[0]{rows} && $sides[1]{rows}) {
	my ($c, $s) = map { $_->{rows} } @sides;
	my $bad   = 0;
	my $maxrel = 0;
	my $cells = 0;
	my $detail = '';

	if (@$c != @$s || (@$c && @{ $c->[0] } != @{ $s->[0] })) {
		result('FAIL', 'xport', sprintf('shape %dx%d vs %dx%d', scalar @$c, @$c ? scalar @{ $c->[0] } : 0, scalar @$s, @$s ? scalar @{ $s->[0] } : 0));
	} else {
		for my $r (0 .. $#$c) {
			for my $col (1 .. $#{ $c->[$r] }) {
				my ($a, $b) = ($c->[$r][$col], $s->[$r][$col]);
				$cells++;
				my $an = $a =~ /nan/i;
				my $bn = $b =~ /nan/i;

				if ($an || $bn) {
					next if $an && $bn;
					$bad++;
					$detail ||= "row $r col $col: cacti $a smokeping $b";
					next;
				}

				next if $a eq $b;
				my $denominator = abs($b) > abs($a) ? abs($b) : abs($a);
				my $rel = $denominator == 0 ? 0 : abs($a - $b) / $denominator;
				$maxrel = $rel if $rel > $maxrel;

				if ($rel > 1e-9) {
					$bad++;
					$detail ||= "row $r col $col: cacti $a smokeping $b";
				}
			}
		}

		$bad++ if $cells == 0;
		$detail ||= 'no cells compared' if $cells == 0;
		result($bad ? 'FAIL' : 'PASS', 'xport', $bad ? "$bad of $cells cells differ, first: $detail" : sprintf('%d series x %d rows, %d cells, max relative difference %.1e', $#{ $c->[0] }, scalar @$c, $cells, $maxrel));
	}
}

# print: GPRINT items become PRINT of the number; the legend text is not compared.
{
	my @values;

	for my $side (@sides) {
		my ($defs, undef, $prints, $vdef) = @{ $side->{parsed} };
		my @cmd = ($rrdtool, 'graph', '/dev/null', '--start', $start, '--end', $end, @$defs);

		for my $p (@$prints) {
			my @f = split /:/, $p, 5;
			shift @f;

			if ($vdef->{ $f[0] }) {
				push @cmd, "PRINT:$f[0]:%le";
			} else {
				push @cmd, "PRINT:$f[0]:$f[1]:%le";
			}
		}

		my ($status, $text) = run(@cmd);
		my @lines = grep { /e[+-]\d+$|nan/i } split /\n/, $text;
		$side->{prints} = \@lines;
	}

	my ($c, $s) = map { $_->{prints} } @sides;
	my $ok     = @$c == @$s && @$c > 0;
	my $detail = $ok ? scalar(@$c) . ' values' : sprintf('%d vs %d values', scalar @$c, scalar @$s);

	if ($ok) {
		for my $i (0 .. $#$c) {
			my ($a, $b) = ($c->[$i], $s->[$i]);

			if ($a =~ /nan/i || $b =~ /nan/i) {
				next if $a =~ /nan/i && $b =~ /nan/i;
				$ok = 0;
				$detail = "value $i: cacti $a smokeping $b";
				last;
			}

			my $denominator = abs($b) > abs($a) ? abs($b) : abs($a);
			next if $denominator == 0 || abs($a - $b) / $denominator <= 1e-9;
			$ok = 0;
			$detail = "value $i: cacti $a smokeping $b";
			last;
		}
	}

	result($ok ? 'PASS' : 'FAIL', 'print', $detail);
}

# png: the plot area alone, so titles, legends, and axis labels do not enter.
{
	my @md5;

	for my $side (@sides) {
		my ($defs) = @{ $side->{parsed} };
		my ($fh, $png) = tempfile(SUFFIX => '.png', UNLINK => 1);
		close $fh;

		my @cmd = (
			$rrdtool, 'graph', $png, '--only-graph', '--imgformat', 'PNG',
			'--start', $start, '--end', $end, '--height', $height, '--width', $width,
			'--rigid', '--upper-limit', $max, '--lower-limit', 0,
			@$defs, @{ $side->{parsed}[1] },
		);

		my ($status, $text) = run(@cmd);

		if ($status != 0) {
			result('FAIL', 'png', "$side->{name}: " . substr($text, 0, 200));
			@md5 = ();
			last;
		}

		open my $in, '<:raw', $png or die;
		local $/;
		push @md5, md5_hex(<$in>);
		close $in;
	}

	result($md5[0] eq $md5[1] ? 'PASS' : 'FAIL', 'png', $md5[0] eq $md5[1] ? $md5[0] : "cacti $md5[0] smokeping $md5[1]") if @md5 == 2;
}

exit $failed;
