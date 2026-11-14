# SmokePing distribution matrix

Research for [#14](https://github.com/somethingwithproof/plugin_smokeping/issues/14). Observed 2026-11-13 by installing the vendor package in each distribution's official container image and starting the daemon the way the packaged service does. The scripts in this directory reproduce every row:

```sh
docker run --rm -v "$PWD/probe.sh:/probe.sh:ro" debian:13 sh /probe.sh deb
docker run --rm -v "$PWD/runtime.sh:/runtime.sh:ro" debian:13 sh /runtime.sh deb
```

The family argument is `deb`, `el`, `fedora`, or `alpine`. Images were the aarch64 variants; every SmokePing package is `noarch` or architecture-independent Perl, but `fping` is native and its behavior was not compared across architectures.

## Which distributions are in scope

A release is in scope while its vendor gives it full support: Debian regular support (not LTS), Ubuntu standard maintenance (not ESM), and Enterprise Linux Full Support (not Maintenance Support). Dates are from [endoflife.date](https://endoflife.date) on 2026-11-13.

| Distribution | Full support ends | SmokePing package | Source |
|---|---|---|---|
| Debian 13 | 2028-08-09 | 2.8.2+ds-1 | main |
| Ubuntu 22.04 LTS | 2027-06-01 | 2.7.3-3 | universe |
| Ubuntu 24.04 LTS | 2029-05-31 | 2.8.2+ds-1 | universe |
| Ubuntu 26.04 LTS | 2031-05-29 | 2.8.2+ds-1build1 | universe |
| Ubuntu 26.10 | 2027-07-01 | 2.8.2+ds-2 | universe |
| EL 9 (AlmaLinux 9.8) | 2027-05-31 | 2.9.0-12.el9 | EPEL 9, needs CRB |
| EL 10 | 2030-05-31 | none | EPEL 10 has never built it (Bodhi) |
| Fedora 43 | 2026-12-01 | 2.9.0-14.fc43 | fedora |
| Fedora 44 | 2027-06-02 | 2.9.0-15.fc44 | fedora |
| Fedora 45 | 2027-12-08 | 2.9.0-16.fc45 | fedora |
| openSUSE Leap 16.0 | 2027-10-31 | none | not in the vendor repositories |
| Alpine 3.22 | 2027-05-01 | 2.9.0-r0 | main |
| Alpine 3.23 | 2027-11-01 | 2.9.0-r1 | main |
| Alpine 3.24 | 2028-06-01 | 2.9.0-r1 | main |

Debian 12 left regular support on 2026-07-11 and RHEL 8 left Full Support on 2024-05-31, so neither is in scope.

**Version lines.** 2.9.0 is shipped by EL 9, Fedora, and Alpine. 2.8.2 is shipped by Debian 13 and every Ubuntu release except 22.04. 2.7.3 is shipped only by Ubuntu 22.04, whose standard support ends on 2027-06-01. No in-scope distribution ships 2.9.0 on Debian or Ubuntu, and none ships SmokePing for EL 10 or Leap 16.

## Layout

| | Debian, Ubuntu | EL 9, Fedora | Alpine |
|---|---|---|---|
| Daemon user:group | `smokeping:smokeping` | `smokeping:apache` (primary group is `apache`) | `smokeping:smokeping` |
| Main config | `/etc/smokeping/config`, `0644 root:root`, `@include`s seven files in `config.d/` | `/etc/smokeping/config`, `0640 root:apache`, single file | `/etc/smokeping/config`, `0644 root:root`, single file |
| `config.d/` files | `0644 root:root` | none | none |
| `datadir` | `/var/lib/smokeping` | `/var/lib/smokeping/rrd` | `/var/lib/smokeping` |
| Other directories inside `datadir` | `__cgi` (the CGI `dyndir`), `2775 smokeping:www-data` | none | `images` (the `imgcache`) |
| Directory mode | `0755` | `0755` | `2755` (setgid) |
| RRD file mode | `0644 smokeping:smokeping` | `0644 smokeping:apache` | `0644 smokeping:smokeping` |
| Daemon umask | `0022` | `0022` | `0022` |
| `piddir` | `/run/smokeping` | `/run/smokeping` | `/run/smokeping` |
| Binaries | `/usr/sbin/smokeping`, `/usr/sbin/smokeinfo` | `/usr/sbin/smokeping`, `smokeinfo` in `/usr/bin` (EL 9) or both `/usr/bin` and `/usr/sbin` (Fedora) | `/usr/sbin/smokeping`, `/usr/bin/smokeinfo` |
| Perl library | `/usr/share/perl5/Smokeping.pm` | `/usr/share/smokeping/Smokeping.pm` | `/usr/share/smokeping/Smokeping.pm` |
| `fping` | `/usr/bin/fping`, `cap_net_raw=ep` | `/usr/sbin/fping`, `cap_net_raw=ep` | `/usr/sbin/fping`, no capability; relies on `net.ipv4.ping_group_range` |

RRD files are world-readable on every distribution, and every directory on the path is world-traversable. A Cacti user on the same host can read the RRDs without group membership.

Packaged Cacti runs its poller as `www-data` on Debian (`/etc/cron.d/cacti`, cacti 1.2.30+ds1-1) and as `apache` on EL 9 (commented example in `/etc/cron.d/cacti`, cacti 1.2.30-2.el9). Fedora 43 to 45 ship no Cacti package.

## Service and reload

| | Debian, Ubuntu | EL 9, Fedora | Alpine |
|---|---|---|---|
| Init | systemd unit, plus a sysvinit script | systemd unit | OpenRC script in `smokeping-openrc` |
| Start | `Type=forking`, `smokeping --pid-dir=/run/smokeping` | `smokeping --nodaemon`, `ExecStartPre=/usr/libexec/smokeping-fix-ownership` | `start-stop-daemon --user smokeping:smokeping` |
| PID file | written | **not written** | written |
| `systemctl reload` | `ExecReload=/bin/kill -HUP $MAINPID` | `ExecReload=/bin/kill -HUP $MAINPID` | not applicable |
| `smokeping --reload` | works as root and as `smokeping` | **fails**: `Can not read pid from /run/smokeping/smokeping.pid` | works as root and as `smokeping` |
| Packaged reload | sysvinit script runs `smokeping --reload` | `systemctl reload` | **broken**: `rc-service smokeping reload` calls `/usr/bin/smokeping`, which does not exist on 3.22, 3.23, or 3.24 |

`smokeping --reload` reads `piddir/smokeping.pid` and sends `SIGHUP` (`Smokeping.pm`, `main`). Under `--nodaemon`, `daemonize_me` never runs, so no PID file exists. Any user other than root or `smokeping` gets `no instance of SmokePing running` because it cannot signal the process.

`smokeping-fix-ownership` on EL and Fedora chowns any root-owned file under `/var/lib/smokeping/rrd` to `smokeping:apache` before each start.

## `--check`

`--check` calls `verify_cfg`, which runs `get_config(get_parser, $cfgfile)`: the same parser and validators the daemon uses. Some validators test the host as well as the syntax, for example `Directory '...' does not exist unless -d` and `File '...' does not exist unless -f` (`Smokeping.pm`, `get_parser`):

- On Debian and Ubuntu, `--check` fails on a fresh install until `/run/smokeping` exists: `Directory '/run/smokeping' does not exist`. systemd creates it through `RuntimeDirectory=` only while the service runs.
- On EL 9 and Fedora, the default config fails without `/usr/sbin/sendmail`: `File '/usr/sbin/sendmail' does not exist`. The containers had no MTA. The runtime script creates a stub so the remaining checks can run.

With those present, `--check` succeeded as root and as `smokeping` everywhere; as `www-data` on Debian and Ubuntu; as `apache` on EL 9 and Fedora; and as `nobody` everywhere except EL 9 and Fedora, where `nobody` cannot read the `0640` config (`exit 13`). `smokeinfo` behaves the same way.

## Effect on the plan

- **2.7.3** stays in the matrix only until Ubuntu 22.04 leaves standard support on 2027-06-01.
- **EL 10 and Leap 16** have no vendor package. Supporting them means supporting a source install, which the matrix does not cover.
- **Reload ([#51](https://github.com/somethingwithproof/plugin_smokeping/issues/51)).** The shipped `.service` unit must run `systemctl reload smokeping.service`, not `smokeping --reload`, because the latter fails on EL 9 and Fedora. Alpine has no systemd, so managed mode's path-unit design does not apply there, and its own reload is broken.
- **`--check` ([#50](https://github.com/somethingwithproof/plugin_smokeping/issues/50)).** Candidate validation depends on the host: `piddir` must exist and every referenced file, such as `sendmail`, must exist. A failed check is not proof of a bad candidate, so the writer must run `--check` on the live config first and treat a failing baseline as an environment error.
- **Poller access.** The packaged poller users (`www-data`, `apache`) can read the config and the RRDs. The plan's "group membership" wording for read access is stronger than needed on these packages; the health page should check read access, not group membership.
- **`datadir` contents.** Debian puts the CGI's writable `__cgi` directory inside `datadir`, and Alpine puts `images` there. Inventory must come from the parser, not a directory walk, and `realpath()` checks under `datadir` must also reject anything that is not an `.rrd` file named by inventory.
- **RRD size.** The default target's RRD is 2,986,808 bytes on 2.7.3 and 11,942,456 bytes on 2.8.2 and 2.9.0. The cause is not yet identified and belongs to [#15](https://github.com/somethingwithproof/plugin_smokeping/issues/15).
