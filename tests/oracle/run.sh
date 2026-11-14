#!/bin/sh
# Differential tests of the plugin's SmokePing configuration parser against
# SmokePing's own (Smokeping::get_parser() through tests/oracle/smokeping-inventory.pl).
#
#   sh tests/oracle/run.sh <work-dir>
#
# Environment: SEED (default 20261113), VALID (1000), BROKEN (200), BIG (10000).
#
# For SmokePing 2.7.3 (ubuntu:22.04), 2.8.2 (debian:13) and 2.9.0 (fedora:45)
# the oracle and the PHP parser read the same files at the same paths: the
# generated valid, broken and 10,000-target configurations, each
# distribution's packaged configurations, and tests/Fixtures/inventory.conf.
# Valid files must give the same inventory field for field; broken ones must
# be refused by both, naming the same line wherever SmokePing names one.
#
# Prints one RESULT line per comparison and exits non-zero on any failure.
set -eu

HERE=$(cd "$(dirname "$0")" && pwd)
PLUGIN=$(cd "$HERE/../.." && pwd)
mkdir -p "${1:?usage: run.sh <work-dir>}"
WORK=$(cd "$1" && pwd)
PHP_IMAGE=${PHP_IMAGE:-php:8.2-cli}
status=0

rm -rf "$WORK/gen" "$WORK/pkg" "$WORK/out"
mkdir -p "$WORK/out"

docker run --rm -v "$PLUGIN":/p:ro -v "$WORK":/w "$PHP_IMAGE" \
	php /p/tests/oracle/generate.php --seed="${SEED:-20261113}" --valid="${VALID:-1000}" --broken="${BROKEN:-200}" --big="${BIG:-10000}" /w/gen

# The fixture, with the files SmokePing's parser checks for.
sed "s#@DIR@#/w/gen/env#g" "$PLUGIN/tests/Fixtures/inventory.conf" > "$WORK/gen/fixture.conf"
cp "$WORK/gen/env/fping" "$WORK/gen/env/dig"
echo /w/gen/fixture.conf > "$WORK/gen/fixture.list"

# The PHP side of the generated files does not depend on the distribution.
docker run --rm -v "$PLUGIN":/p:ro -v "$WORK":/w "$PHP_IMAGE" sh -c '
	for k in valid broken fixture; do php /p/tests/oracle/parse.php /w/gen/$k.list > /w/out/php-$k.jsonl; done
	start=$(date +%s.%N); php /p/tests/oracle/parse.php /w/gen/big.list --time > /w/out/php-big.jsonl; end=$(date +%s.%N)
	echo "$start $end" > /w/out/php-big.time'

for spec in ubuntu:22.04:2.7.3 debian:13:2.8.2 fedora:45:2.9.0; do
	base=${spec%:*}
	release=${spec##*:}
	name=${base%%:*}
	image=smokeping-oracle-$name:$release

	if ! docker image inspect "$image" >/dev/null 2>&1; then
		case $name in
			fedora) install='dnf install -y -q smokeping && dnf clean all' ;;
			*) install='apt-get update -qq && DEBIAN_FRONTEND=noninteractive apt-get install -y -qq --no-install-recommends smokeping >/dev/null && rm -rf /var/lib/apt/lists/*' ;;
		esac
		printf 'FROM %s\nRUN %s\n' "$base" "$install" | docker build -q -t "$image" - >/dev/null
	fi

	mkdir -p "$WORK/pkg/$name"

	# The oracle runs without a network, as root, with what a running SmokePing
	# host has: its piddir, a sendmail, and the probe binaries the packaged
	# examples name.
	docker run --rm --network none -v "$PLUGIN":/p:ro -v "$WORK":/w -e NAME="$name" "$image" sh -c '
		set -e
		mkdir -p /run/smokeping /var/run/smokeping
		[ -e /usr/sbin/sendmail ] || { printf "#!/bin/sh\n" > /usr/sbin/sendmail; chmod 755 /usr/sbin/sendmail; }
		ls /etc/smokeping/config /usr/share/doc/smokeping/examples/config.* 2>/dev/null | grep -v "\.gz$" > /w/pkg/$NAME/list || true
		for b in $(cat $(cat /w/pkg/$NAME/list) $(ls /etc/smokeping/config.d/* 2>/dev/null) | sed -n "s/^[[:space:]]*binary[[:space:]]*=[[:space:]]*\([^[:space:]#]*\).*/\1/p" | sort -u); do
			[ -e "$b" ] || { mkdir -p "$(dirname "$b")"; printf "#!/bin/sh\n" > "$b"; chmod 755 "$b"; }
		done
		cp -r /etc/smokeping /w/pkg/$NAME/etc
		[ -d /usr/share/doc/smokeping/examples ] && cp -r /usr/share/doc/smokeping/examples /w/pkg/$NAME/examples || mkdir -p /w/pkg/$NAME/examples
		for k in valid broken fixture; do perl /p/tests/oracle/smokeping-inventory.pl --list=/w/gen/$k.list > /w/out/perl-$NAME-$k.jsonl 2>/dev/null; done
		perl /p/tests/oracle/smokeping-inventory.pl --list=/w/pkg/$NAME/list > /w/out/perl-$NAME-pkg.jsonl 2>/dev/null
		start=$(date +%s.%N); perl /p/tests/oracle/smokeping-inventory.pl --list=/w/gen/big.list > /w/out/perl-$NAME-big.jsonl 2>/dev/null; end=$(date +%s.%N)
		echo "$start $end" > /w/out/perl-$NAME-big.time'

	# The packaged files at the paths the oracle read them from.
	docker run --rm -v "$PLUGIN":/p:ro -v "$WORK":/w -v "$WORK/pkg/$name/etc":/etc/smokeping:ro -v "$WORK/pkg/$name/examples":/usr/share/doc/smokeping/examples:ro "$PHP_IMAGE" \
		php /p/tests/oracle/parse.php /w/pkg/$name/list > "$WORK/out/php-$name-pkg.jsonl"

	docker run --rm -v "$PLUGIN":/p:ro -v "$WORK":/w "$PHP_IMAGE" sh -c '
		cmp() { php /p/tests/oracle/compare.php "$1" "$2" "$3" "$4" | grep -E "^(RESULT|DIFF)" | head -20; }
		want=$(echo '"$release"' | awk -F. "{ printf \"%d.%03d%03d\", \$1, \$2, \$3 }")
		got=$(sed -n "s/.*\"smokeping\":\"\([^\"]*\)\".*/\1/p" /w/out/perl-'"$name"'-fixture.jsonl)
		[ "$got" = "$want" ] && echo "RESULT PASS '"$name-$release"'-oracle-version (SmokePing $got)" || echo "RESULT FAIL '"$name-$release"'-oracle-version (wanted $want, the image has $got)"
		cmp '"$name-$release"' /w/out/perl-'"$name"'-valid.jsonl /w/out/php-valid.jsonl valid
		cmp '"$name-$release"'-packaged /w/out/perl-'"$name"'-pkg.jsonl /w/out/php-'"$name"'-pkg.jsonl valid
		cmp '"$name-$release"'-fixture /w/out/perl-'"$name"'-fixture.jsonl /w/out/php-fixture.jsonl valid
		cmp '"$name-$release"'-10k /w/out/perl-'"$name"'-big.jsonl /w/out/php-big.jsonl valid
		cmp '"$name-$release"' /w/out/perl-'"$name"'-broken.jsonl /w/out/php-broken.jsonl broken
		perl=$(awk "{ printf \"%.2f\", \$2 - \$1 }" /w/out/perl-'"$name"'-big.time)
		php=$(awk "{ printf \"%.2f\", \$2 - \$1 }" /w/out/php-big.time)
		parse=$(sed -n "s/.*\"seconds\":\([0-9.]*\).*/\1/p" /w/out/php-big.jsonl)
		echo "INFO '"$name-$release"'-10k-time perl ${perl}s with startup; php ${php}s with startup, ${parse}s parse"' | tee "$WORK/out/result-$name.txt"

	grep -q '^RESULT FAIL\|^DIFF' "$WORK/out/result-$name.txt" && status=1
done

exit $status
