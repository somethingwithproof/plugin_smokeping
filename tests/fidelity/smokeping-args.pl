#!/usr/bin/perl
# Print the rrdtool graph arguments SmokePing's detail graph uses for one RRD,
# one argument per line, with the configuration defaults and without the
# uptime, loss_background, and nodata options (see docs/research/graph-fidelity.md).
#
#   smokeping-args.pl <rrd> <pings> <step> <start> <end> <max> <height> <width>
#
# Smokeping::smokecol comes from the installed module. The rest of the argument
# list is built inline in Smokeping.pm's detail-page code, so this script ports
# it, and prints on stderr a digest of the module's source for the blocks it
# ports so a run on each packaged version shows whether the port covers it.
use strict;
use warnings;
use Digest::SHA qw(sha256_hex);
use List::Util qw(max);
use Smokeping;

my ($rrd, $pings, $step, $start, $end, $max, $height, $width) = @ARGV;
die "usage: smokeping-args.pl rrd pings step start end max height width\n" unless defined $width;

# Digest the source blocks this port mirrors.
{
	open my $fh, '<', $INC{'Smokeping.pm'} or die $!;
	my @src = <$fh>;
	close $fh;
	my $text = join '', @src;
	my %blocks = (
		smokecol => qr/(sub smokecol \(\$\) \{.*?\n\}\n)/s,
		lossmap  => qr/(my \$p = \$pings;.*?\n\s+\);)/s,
		median   => qr/(my \@median = \("DEF:median.*?\$last = \$loss;\n\s+\})/s,
		defs     => qr/(\(map \{"DEF:ping.*?"DEF:loss=\$\{rrd\}:loss:AVERAGE"\))/s,
	);

	for my $name (sort keys %blocks) {
		my ($block) = $text =~ $blocks{$name};
		my $norm = defined $block ? $block : '';
		$norm =~ s/\s+//g;
		printf STDERR "BLOCK %s %s\n", $name, defined $block ? substr(sha256_hex($norm), 0, 16) : 'MISSING';
	}

	printf STDERR "VERSION %s\n", $Smokeping::VERSION // 'unknown';
}

sub display_range {
	my ($lower, $upper) = @_;
	return $upper if $upper < $lower;
	return "$lower-$upper" if $upper > $lower;
	return $lower;
}

my $smoke = Smokeping::smokecol($pings);
my $swidth = $max / $height;

my $p = $pings;
my %lc;

if ($Smokeping::VERSION < 2.008) {
	# 2.7.x: fixed levels, no complete-loss level.
	%lc = (
		0           => ['0',                 '#26ff00'],
		1           => ["1/$p",              '#00b8ff'],
		2           => ["2/$p",              '#0059ff'],
		3           => ["3/$p",              '#5e00ff'],
		4           => ["4/$p",              '#7e00ff'],
		int($p / 2) => [int($p / 2) . "/$p", '#dd00ff'],
		$p - 1      => [($p - 1) . "/$p",    '#ff0000'],
	);
} else {
	my $per01 = max(int(0.01 * $p), 1);
	my $per05 = max(int(0.05 * $p), 2);
	my $per10 = max(int(0.10 * $p), 3);
	my $per25 = max(int(0.25 * $p), 4);
	my $per50 = max(int(0.50 * $p), 5);
	%lc = (
		0      => ['0',                                '#26ff00'],
		$per01 => [display_range(1, $per01),           '#00b8ff'],
		$per05 => [display_range($per01 + 1, $per05),  '#0059ff'],
		$per10 => [display_range($per05 + 1, $per10),  '#7e00ff'],
		$per25 => [display_range($per10 + 1, $per25),  '#ff00ff'],
		$per50 => [display_range($per25 + 1, $per50),  '#ff5500'],
		$p - 1 => [display_range($per50 + 1, $p - 1),  '#ff0000'],
		$p     => ["$p/$p",                            '#a00000'],
	);
}

my @median = (
	"DEF:median=${rrd}:median:AVERAGE",
	"CDEF:ploss=loss,$pings,/,100,*",
	"VDEF:avmed=median,AVERAGE",
	'GPRINT:avmed:median rtt\:  %.1lf %ss avg',
	'GPRINT:median:MAX:%.1lf %ss max',
	'GPRINT:median:MIN:%.1lf %ss min',
	'GPRINT:median:LAST:%.1lf %ss now\l',
	"LINE1:median#202020",
	"GPRINT:ploss:AVERAGE:packet loss\\: %.2lf %% avg",
	"GPRINT:ploss:MAX:%.2lf %% max",
	"GPRINT:ploss:MIN:%.2lf %% min",
	'GPRINT:ploss:LAST:%.2lf %% now\l',
	'COMMENT:loss color\:',
);

my $last = -1;
foreach my $loss (sort { $a <=> $b } keys %lc) {
	next if $loss > $pings;
	my $lvar = $loss;
	$lvar =~ s/\./d/g;
	push @median, (
		"CDEF:me$lvar=loss,$last,GT,loss,$loss,LE,*,1,UNKN,IF,median,*",
		"CDEF:meL$lvar=me$lvar,$swidth,-",
		"CDEF:meH$lvar=me$lvar,0,*,$swidth,2,*,+",
		"AREA:meL$lvar",
		"STACK:meH$lvar$lc{$loss}[1]:$lc{$loss}[0]",
	);
	$last = $loss;
}

my @args = (
	"--start", $start, "--end", $end,
	"--height", $height, "--width", $width,
	"--rigid", "--upper-limit", $max, "--lower-limit", 0,
	(map { "DEF:ping${_}=${rrd}:ping${_}:AVERAGE" } 1 .. $pings),
	(map { "CDEF:cp${_}=ping${_},$max,LT,ping${_},INF,IF" } 1 .. $pings),
	"DEF:loss=${rrd}:loss:AVERAGE",
	@$smoke,
	@median,
	'HRULE:0#000000',
);

print "$_\n" for @args;
