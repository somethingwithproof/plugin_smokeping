# Inventory script on packaged SmokePing

Research for [#18](https://github.com/somethingwithproof/plugin_smokeping/issues/18) and [#29](https://github.com/somethingwithproof/plugin_smokeping/issues/29). Observed 2026-11-13 on aarch64 containers with each distribution's own SmokePing package. `tests/inventory.sh` reproduces every row and runs in CI:

```sh
docker run --rm -v "$PWD:/plugin:ro" debian:13 sh /plugin/tests/inventory.sh
```

The script runs every check as `cactitest`, a fresh user with no group memberships, which stands in for the Cacti poller user.

## How the script reads the configuration

`bin/smokeping-inventory.pl` loads `Smokeping.pm` and calls `Smokeping::get_parser()->parse()`, the same parser the daemon and `smokeinfo` use. It does not load probe modules, open RRD files, or write anything. Debian installs `Smokeping.pm` in Perl's default `@INC`; Alpine and Fedora install it under `/usr/share/smokeping`, which the script adds when present. A `--libdir` option covers source installs.

Step and pings follow SmokePing's own resolution: a target's `pings` overrides the probe's, then the parent probe's, then `*** Database ***`. A probe `step` applies unless `concurrentprobes = no`. Targets whose `host` starts with `/` are multi-host graphs with no RRD and are left out.

## Results

| Check | Debian 13 (2.8.2) | Alpine 3.24 (2.9.0) | Fedora 44 (2.9.0) |
|---|---|---|---|
| Fixture config, unprivileged | identical to `tests/Fixtures/inventory.json` | identical | identical |
| Broken config | exit 255, parser error with file and line | same | same |
| Unreadable config | exit 13, `Permission denied` | same | same |
| 10,000 targets | 1.14 s | 1.00 s | 1.10 s |
| Packaged config as `cactitest` | exit 2: `/run/smokeping` does not exist | 3 targets | exit 13: config is `0640 root:apache` |
| Packaged config, daemon state present | 1 target (also as `www-data`) | n/a | as `apache`: exit 2, `/usr/sbin/sendmail` does not exist |

The fixture output matched byte for byte across all three apart from the version string, so the 2.8 and 2.9 parsers agree on every field the plugin uses.

## Permission findings

- The parser checks for files the configuration names, not only the configuration itself. On Debian it fails until the daemon has created `piddir` (`/run/smokeping`); on Fedora it fails when `sendmail` named in `*** General ***` is missing. Both are true on a host with a working SmokePing and false in a bare container. The plugin reports the parser's message on the health page and keeps the previous inventory.
- Fedora ships the configuration as `0640 root:apache`. A poller running as `apache` can read it; any other poller user needs group access granted by the administrator. The plugin does not change file modes.
- The parser refuses a world-readable `secrets` file but only checks its mode, so a `0600` file owned by the SmokePing user does not stop an unprivileged reader.
- Nothing needed root.

## Run time

10,000 targets in 100 sections parse in about one second on each distribution, well inside the default 300 s inventory interval and the 120 s reader budget. The plugin runs the inventory at most once per interval.
