#!/bin/sh
# Run the commands of docs/install.md that are marked <!-- verify:NAME --> on a
# real distribution, in a privileged container (the mount needs CAP_SYS_ADMIN):
#
#   docker run --rm --privileged -v "$PWD:/plugin:ro" debian:13 sh /plugin/tests/docs-commands.sh
#   docker run --rm --privileged -v "$PWD:/plugin:ro" almalinux:9 sh /plugin/tests/docs-commands.sh
#
# Each marked fenced block runs with `sh -e` under the variables the guide
# defines (DATADIR, RRA, POLLER_USER). The guide is the source of truth: this
# script contains no copy of its commands. When no systemd runs as PID 1,
# `systemctl` is replaced by a stub that performs the mount the unit
# describes, with the arguments systemd itself passes to mount(8); the unit
# file is still checked by the real `systemd-analyze verify`.
#
# Prints one RESULT line per block and exits non-zero on any failure.
set -u

DOC=/plugin/docs/install.md
fail=0
result() { echo "RESULT $1 $2${3:+ ($3)}"; [ "$1" = PASS ] || [ "$1" = INFO ] || fail=1; }

. /etc/os-release
echo "on $PRETTY_NAME"

case "$ID" in
	debian|ubuntu)
		FAMILY=deb
		DATADIR=/var/lib/smokeping
		POLLER_USER=www-data
		apt-get update -qq >/dev/null 2>&1
		DEBIAN_FRONTEND=noninteractive apt-get install -y -qq util-linux mount systemd >/dev/null 2>&1
		;;
	almalinux|rocky|rhel|centos)
		FAMILY=el
		DATADIR=/var/lib/smokeping/rrd
		POLLER_USER=apache
		dnf install -y -q util-linux systemd 'dnf-command(config-manager)' >/dev/null 2>&1
		;;
	*) echo "unsupported distribution $ID"; exit 2;;
esac

RRA=/var/www/html/cacti/rra
export DATADIR RRA POLLER_USER

block() {
	awk -v marker="<!-- verify:$1 -->" '
		$0 == marker { found = 1; next }
		found && /^```/ { if (open) exit; open = 1; next }
		found && open { print }
	' "$DOC"
}

run() {
	name=$1
	want=${2:-}
	block "$name" > /tmp/block.sh

	if [ ! -s /tmp/block.sh ]; then
		result FAIL "$name" "no block with that marker in install.md"

		return 1
	fi

	if sh -e /tmp/block.sh > /tmp/block.out 2>&1 && { [ -z "$want" ] || grep -q -- "$want" /tmp/block.out; }; then
		result PASS "$name" "$(tail -1 /tmp/block.out | cut -c1-100)"
	else
		result FAIL "$name" "$(tail -3 /tmp/block.out | tr '\n' ' ' | cut -c1-200)"

		return 1
	fi
}

# 1. SmokePing from the distribution's packages.
if [ "$FAMILY" = deb ]; then
	run install-smokeping-deb
else
	run install-smokeping-el
fi

[ -d "$DATADIR" ] && result PASS smokeping-datadir-exists "$DATADIR" || result FAIL smokeping-datadir-exists "$DATADIR missing after the package install"

# A SmokePing section with an RRD file in it, as the daemon would write.
mkdir -p "$DATADIR/Europe"
echo placeholder > "$DATADIR/Europe/paris.rrd"
chmod 644 "$DATADIR/Europe/paris.rrd"

# 2. The poller user.
id "$POLLER_USER" >/dev/null 2>&1 || useradd -r -M -s /sbin/nologin "$POLLER_USER"
run poller-access 'datadir listable'

# 3. Option A: fstab.
cp /etc/fstab /tmp/fstab.orig 2>/dev/null || : > /tmp/fstab.orig
run mount-fstab
run mount-verify 'read-only, as required'
[ -f "$RRA/smokeping/Europe/paris.rrd" ] && result PASS mount-shows-datadir "paris.rrd visible through the mount" || result FAIL mount-shows-datadir "file not visible"
umount "$RRA/smokeping" && cp /tmp/fstab.orig /etc/fstab

# Option B: the systemd unit.
if [ ! -d /run/systemd/system ]; then
	mkdir -p /tmp/stub
	cat > /tmp/stub/systemctl <<'STUB'
#!/bin/sh
# No systemd as PID 1: perform the mount the unit describes.
case "$1" in
	daemon-reload) exit 0;;
	enable)
		unit=/etc/systemd/system/$3
		what=$(sed -n 's/^What=//p' "$unit"); where=$(sed -n 's/^Where=//p' "$unit"); opts=$(sed -n 's/^Options=//p' "$unit"); type=$(sed -n 's/^Type=//p' "$unit")
		exec mount -t "$type" -o "$opts" "$what" "$where";;
	*) echo "systemctl stub: $*" >&2; exit 1;;
esac
STUB
	chmod +x /tmp/stub/systemctl
	PATH=/tmp/stub:$PATH
	result INFO systemd-not-running "systemctl is a stub that performs the unit's mount; systemd-analyze verify is real"
fi

mkdir -p "$RRA/smokeping"
run mount-unit
run mount-verify 'read-only, as required'
unit=$(systemd-escape --path --suffix=mount "$RRA/smokeping")
[ "$unit" = "var-www-html-cacti-rra-smokeping.mount" ] && result PASS unit-name "$unit" || result FAIL unit-name "$unit"

exit $fail
