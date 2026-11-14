#!/bin/sh
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

# Runs inside a distro container after install: start the daemon the way the
# packaged service does, then record RRD modes, --check, and --reload behavior.
set -u
fam="$1"
case "$fam" in
  deb)    export DEBIAN_FRONTEND=noninteractive; apt-get update -qq >/dev/null; apt-get install -y -qq smokeping procps >/dev/null 2>&1;;
  el)     dnf -y -q install epel-release dnf-plugins-core >/dev/null 2>&1; dnf config-manager --set-enabled crb >/dev/null 2>&1; dnf -y -q install smokeping procps-ng >/dev/null 2>&1;;
  fedora) dnf -y -q install smokeping procps-ng util-linux >/dev/null 2>&1;;
  alpine) apk add -q smokeping smokeping-openrc procps >/dev/null 2>&1;;
esac
SP=$(command -v smokeping || echo /usr/sbin/smokeping)
asuser(){ u=$1; shift; if command -v runuser >/dev/null; then runuser -u "$u" -- sh -c "$*"; else su -s /bin/sh "$u" -c "$*"; fi; }
CFG=/etc/smokeping/config
. /etc/os-release; echo "=== $PRETTY_NAME"
echo "--- targets (parser view)"
smokeinfo $CFG 2>&1 | head -5
echo "--- check as root, fresh install"; $SP --config=$CFG --check; echo "exit=$?"
mkdir -p /run/smokeping; chown smokeping /run/smokeping
[ -e /usr/sbin/sendmail ] || { echo "(no /usr/sbin/sendmail; creating stub)"; printf '#!/bin/sh\nexit 0\n' >/usr/sbin/sendmail; chmod 755 /usr/sbin/sendmail; }
echo "--- check as root, piddir+sendmail present"; $SP --config=$CFG --check; echo "exit=$?"
echo "--- check as smokeping"; asuser smokeping "$SP --config=$CFG --check"; echo "exit=$?"
id www-data >/dev/null 2>&1 && { echo "--- check as www-data"; asuser www-data "$SP --config=$CFG --check"; echo "exit=$?"; }
id apache >/dev/null 2>&1 && { echo "--- check as apache"; asuser apache "$SP --config=$CFG --check"; echo "exit=$?"; }
echo "--- check as nobody"; asuser nobody "$SP --config=$CFG --check"; echo "exit=$?"
case "$fam" in
  el|fedora) /usr/libexec/smokeping-fix-ownership; runuser -u smokeping -g apache -- $SP --config=$CFG --nodaemon >/tmp/sp.log 2>&1 & ;;
  deb)       asuser smokeping "$SP --config=$CFG --pid-dir=/run/smokeping" >/tmp/sp.log 2>&1 ;;
  alpine)    asuser smokeping "$SP --config=$CFG" >/tmp/sp.log 2>&1 ;;
esac
sleep 8
echo "--- processes"; ps -eo user,group,pid,args | grep -i '[s]moke'
echo "--- pid file"; ls -l /run/smokeping/ 2>&1
echo "--- umask of daemon"; for p in $(pgrep -f 'smokeping'); do grep -i umask /proc/$p/status; done
echo "--- datadir tree"
DD=$(sed -n 's/^\s*datadir\s*=\s*//p' $CFG /etc/smokeping/config.d/pathnames 2>/dev/null | head -1)
echo "datadir=$DD"; ls -laR "$DD" 2>&1 | head -40
echo "--- reload as root via smokeping --reload"; $SP --config=$CFG --reload; echo "exit=$?"
echo "--- reload as smokeping via smokeping --reload"; asuser smokeping "$SP --config=$CFG --reload"; echo "exit=$?"
sleep 2; echo "--- daemon log"; tail -15 /tmp/sp.log
echo "--- reload as nobody"; asuser nobody "$SP --config=$CFG --reload"; echo "exit=$?"
echo "--- smokeinfo as nobody"; asuser nobody "smokeinfo $CFG" 2>&1 | head -3
echo "--- end"
