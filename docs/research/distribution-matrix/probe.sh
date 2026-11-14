#!/bin/sh
# Runs inside a distro container: installs packaged SmokePing and reports layout facts.
set -u
fam="$1"
case "$fam" in
  deb)    export DEBIAN_FRONTEND=noninteractive; apt-get update -qq >/dev/null && apt-get install -y -qq smokeping >/dev/null 2>&1 || echo "INSTALL_FAILED";;
  el)     dnf -y -q install epel-release dnf-plugins-core >/dev/null 2>&1; dnf config-manager --set-enabled crb >/dev/null 2>&1; dnf -y -q install smokeping >/dev/null 2>&1 || echo "INSTALL_FAILED";;
  fedora) dnf -y -q install smokeping >/dev/null 2>&1 || echo "INSTALL_FAILED";;
  suse)   zypper -n -q in smokeping >/dev/null 2>&1 || echo "INSTALL_FAILED";;
  alpine) apk add -q smokeping >/dev/null 2>&1 || echo "INSTALL_FAILED";;
esac
echo "--- os"; . /etc/os-release; echo "$PRETTY_NAME"
echo "--- pkgversion"
case "$fam" in
  deb) dpkg-query -W -f='${Version}\n' smokeping ;;
  alpine) apk info -v smokeping 2>/dev/null | head -1 ;;
  *) rpm -q smokeping ;;
esac
echo "--- smokeping VERSION constant"
for f in /usr/share/perl5/Smokeping.pm /usr/share/smokeping/Smokeping.pm /usr/lib/smokeping/Smokeping.pm /usr/share/smokeping/lib/Smokeping.pm; do [ -f "$f" ] && { echo "$f"; grep -m1 -E 'VERSION *=' "$f"; }; done
find / -xdev -name Smokeping.pm -not -path '/proc/*' 2>/dev/null
echo "--- binaries"; command -v smokeping smokeinfo 2>/dev/null; ls -l /usr/sbin/smokeping /usr/bin/smokeping /usr/bin/smokeinfo /usr/sbin/smokeinfo 2>/dev/null
echo "--- users"; getent passwd smokeping; getent group smokeping
echo "--- config files"
for d in /etc/smokeping /etc/smokeping/config.d; do [ -e "$d" ] && ls -la "$d"; done
echo "--- datadir/piddir/imgcache directives"
grep -rhE '^\s*(datadir|piddir|imgcache|dyndir|smokemail|tmail|cgiurl|secrets)\s*=' /etc/smokeping 2>/dev/null
echo "--- @include lines"; grep -rn '@include' /etc/smokeping 2>/dev/null
echo "--- data dirs"
for d in /var/lib/smokeping /var/lib/smokeping/rrd /var/lib/smokeping/data /var/smokeping /var/run/smokeping /run/smokeping /var/cache/smokeping; do [ -e "$d" ] && ls -ld "$d"; done
echo "--- systemd units"
for u in $(find / -xdev \( -path /proc -prune \) -o -name 'smokeping*.service' -print -o -name 'smokeping*.path' -print 2>/dev/null); do echo "## $u"; cat "$u"; done
echo "--- sysv/openrc"; ls -l /etc/init.d/smokeping 2>/dev/null && grep -nE 'reload|start-stop|--user|-u |command_user|USER' /etc/init.d/smokeping | head -30
echo "--- defaults"; cat /etc/default/smokeping /etc/sysconfig/smokeping /etc/conf.d/smokeping 2>/dev/null
echo "--- fping"; ls -l $(command -v fping fping6 2>/dev/null) 2>/dev/null; getcap $(command -v fping 2>/dev/null) 2>/dev/null
echo "--- reload option in smokeping"; grep -nE "'reload'|reload" $(find / -xdev -name Smokeping.pm -not -path '/proc/*' 2>/dev/null | head -1) 2>/dev/null | head -8
echo "--- end"
