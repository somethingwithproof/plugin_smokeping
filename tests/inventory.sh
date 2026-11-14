#!/bin/sh
# Exercise bin/smokeping-inventory.pl against the SmokePing a distribution
# packages, as an unprivileged user. Run inside a debian, alpine, or fedora
# container with the plugin mounted at /plugin:
#
#   docker run --rm -v "$PWD:/plugin:ro" debian:13 sh /plugin/tests/inventory.sh
#
# Prints one RESULT line per check and exits non-zero on any failure.
set -u

SCRIPT=/plugin/bin/smokeping-inventory.pl
FIX=/plugin/tests/Fixtures
fail=0

result() { echo "RESULT $1 $2${3:+ ($3)}"; [ "$1" = PASS ] || [ "$1" = INFO ] || fail=1; }

if ! command -v smokeping >/dev/null 2>&1 && [ ! -x /usr/sbin/smokeping ]; then
	if command -v apt-get >/dev/null 2>&1; then
		apt-get update -qq && DEBIAN_FRONTEND=noninteractive apt-get install -y -qq --no-install-recommends smokeping >/dev/null
	elif command -v apk >/dev/null 2>&1; then
		apk add --no-cache -q smokeping perl
	elif command -v dnf >/dev/null 2>&1; then
		dnf install -y -q smokeping util-linux >/dev/null
	fi
fi

id cactitest >/dev/null 2>&1 || useradd -M -s /bin/sh cactitest 2>/dev/null || adduser -D -H cactitest
as_user() { su cactitest -s /bin/sh -c "$*"; }

version=$(perl -I/usr/share/smokeping -MSmokeping -e 'print $Smokeping::VERSION' 2>/dev/null)
echo "SmokePing $version on $(. /etc/os-release; echo "$PRETTY_NAME")"

# 1. The packaged configuration, as the packaged daemon would see it.
out=$(as_user "perl $SCRIPT --config=/etc/smokeping/config" 2>&1)
rc=$?
if [ $rc -eq 0 ]; then
	result PASS packaged-config "$(echo "$out" | grep -o '"path":"[^"]*"' | wc -l) targets as unprivileged user"
else
	result INFO packaged-config "exit $rc: $(echo "$out" | head -1)"
fi

# The packaged daemon creates piddir at start; with it present, as a
# running daemon would leave it, retry as the unprivileged user.
piddir=/run/smokeping
if [ ! -d "$piddir" ]; then
	mkdir -p "$piddir"
	out=$(as_user "perl $SCRIPT --config=/etc/smokeping/config" 2>&1)
	rc=$?
	[ $rc -eq 0 ] && result INFO packaged-config-with-piddir "$(echo "$out" | grep -o '"path":"[^"]*"' | wc -l) targets" || result INFO packaged-config-with-piddir "exit $rc: $(echo "$out" | head -1)"
	rmdir "$piddir"
fi

# 2. The fixture configuration, with every file the parser checks present.
dir=$(mktemp -d)
mkdir -p "$dir/cache" "$dir/data" "$dir/run"
touch "$dir/smokemail" "$dir/tmail" "$dir/basepage.html" "$dir/secrets"
printf '#!/bin/sh\n' > "$dir/fping"; cp "$dir/fping" "$dir/dig"; chmod 755 "$dir/fping" "$dir/dig"
sed "s#@DIR@#$dir#g" "$FIX/inventory.conf" > "$dir/config"
chmod -R a+rX "$dir"
# SmokePing refuses a world-readable secrets file; it only checks the mode.
chmod 600 "$dir/secrets"

out=$(as_user "perl $SCRIPT --config=$dir/config" 2>&1)
rc=$?
expected=$(sed "s#@DIR@#$dir#g; s#@VERSION@#$version#" "$FIX/inventory.json")
if [ $rc -eq 0 ] && [ "$out" = "$expected" ]; then
	result PASS fixture-config "output matches tests/Fixtures/inventory.json"
else
	result FAIL fixture-config "exit $rc"
	echo "$out" > /tmp/inventory-actual.json
	echo "$out"
fi

# 3. A broken configuration fails with SmokePing's own parser error.
printf '*** Targets ***\n+ bad.section\nhost = x\n' > "$dir/broken"
cat "$dir/config" | sed '/^\*\*\* Targets/,$d' > "$dir/broken-config"
cat "$dir/broken" >> "$dir/broken-config"
out=$(as_user "perl $SCRIPT --config=$dir/broken-config" 2>&1)
rc=$?
if [ $rc -ne 0 ] && echo "$out" | grep -q '^ERROR:'; then
	result PASS broken-config "exit $rc: $(echo "$out" | head -1)"
else
	result FAIL broken-config "exit $rc"
fi

# 4. An unreadable configuration fails cleanly.
chmod 600 "$dir/config"
out=$(as_user "perl $SCRIPT --config=$dir/config" 2>&1)
rc=$?
[ $rc -ne 0 ] && echo "$out" | grep -q "^ERROR:" && result PASS unreadable-config "exit $rc: $(echo "$out" | head -1)" || result FAIL unreadable-config "exit $rc"
chmod 644 "$dir/config"

# 5. Run time on 10,000 targets (100 sections of 100).
sed '/^\*\*\* Targets/,$d' "$dir/config" > "$dir/big"
{
	printf '*** Targets ***\nprobe = FPingNormal\nmenu = Top\ntitle = Big\n'
	s=0
	while [ $s -lt 100 ]; do
		printf '+ s%d\nmenu = s%d\ntitle = s%d\n' $s $s $s
		t=0
		while [ $t -lt 100 ]; do
			printf '++ t%d\nhost = 192.0.2.%d\n' $t $((t + 1))
			t=$((t + 1))
		done
		s=$((s + 1))
	done
} >> "$dir/big"
start=$(date +%s.%N 2>/dev/null || date +%s)
count=$(as_user "perl $SCRIPT --config=$dir/big" | grep -o '"path":' | wc -l)
end=$(date +%s.%N 2>/dev/null || date +%s)
secs=$(awk "BEGIN { printf \"%.2f\", $end - $start }")
[ "$count" -eq 10000 ] && result PASS 10k-targets "$count targets in ${secs}s" || result FAIL 10k-targets "$count targets"

rm -rf "$dir"
exit $fail
