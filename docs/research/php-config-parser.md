# The PHP parser of SmokePing's configuration

Research for [#29](https://github.com/somethingwithproof/plugin_smokeping/issues/29) and [#18](https://github.com/somethingwithproof/plugin_smokeping/issues/18), and the evidence behind [decision 0009](../decisions/0009-php-configuration-parser.md). Observed 2026-11-13. `tests/oracle/run.sh` reproduces every number here:

```sh
sh tests/oracle/run.sh /tmp/oracle
```

## What is compared

The oracle is the inventory script the plugin ran before 0009, now `tests/oracle/smokeping-inventory.pl`. It calls `Smokeping::get_parser()->parse()` from the installed SmokePing and prints the inventory as JSON. `tests/oracle/parse.php` prints `SmokepingInventory::document()` of `SmokepingConfigParser` for the same files. Both read the same paths: the PHP side runs with the oracle's files mounted where the oracle saw them, so error messages and include paths compare exactly.

| SmokePing | Image | Config::Grammar |
|---|---|---|
| 2.7.3 | `ubuntu:22.04` | 1.13 |
| 2.8.2 | `debian:13` | 1.13 |
| 2.9.0 | `fedora:45` | 1.13 |

The oracle runs as root without a network, with SmokePing's piddir, a `sendmail`, and stubs for the probe binaries the packaged examples name, because SmokePing's grammar checks that those exist.

Inputs:

- **1,000 valid configurations** from `tests/oracle/generate.php`, seed 20261113. Each has random target trees up to five levels deep with menus, titles, `host` (including multi-host `/` targets, which are left out), probes and sub-probes of FPing, FPing6, DNS, TCPPing, Curl, TraceroutePing and DismanPing with step and pings overrides, `concurrentprobes`, `slaves` and `nomasterpoll`, a Slaves section, the Database table, comments, blank lines, CRLF line ends, continuation lines, and `@include` of files in the same directory and in subdirectories.
- **201 broken configurations**: a valid configuration with one of 23 faults (unknown section, nesting, a repeated section or variable, an unknown variable, pings below 3, step 0, an undefined probe, a probe after the host, a bad section name, bad `nomasterpoll` and `slaves` values, a short or invalid Database table row, a variable before any section or in Probes, an error on a continuation line, an unknown probe module, a width below 10, a missing mandatory variable, a missing include, an include from a subdirectory that is missing, pings in the Presentation section), plus one include cycle.
- **One 10,000-target configuration**: 100 sections of 100 FPing targets.
- **The fixture** `tests/Fixtures/inventory.conf`.
- **Each distribution's packaged configurations**: `/etc/smokeping/config` on all three, and on Debian the six `config.*` examples in `/usr/share/doc/smokeping/examples`.

## Results

| Input | 2.7.3 | 2.8.2 | 2.9.0 |
|---|---|---|---|
| Valid, same inventory field for field | 1000/1000 | 1000/1000 | 1000/1000 |
| Packaged, same inventory | 1/1 | 7/7 | 1/1 |
| Fixture, same inventory | 1/1 | 1/1 | 1/1 |
| 10,000 targets, same inventory | 1/1 | 1/1 | 1/1 |
| Broken, refused by both | 201/201 | 201/201 | 201/201 |
| Broken where SmokePing names a line: same file and line | 176/176 | 176/176 | 176/176 |
| Of those, identical message text | 176/176 | 176/176 | 176/176 |

The inventory's `smokeping` field (SmokePing's version) is the one field compared by its absence: the PHP parser does not know which SmokePing wrote the file and leaves it empty.

In the 25 broken files where SmokePing names no line, both refuse, and:

- **Missing mandatory variable (8).** The same text, `file (Section): mandatory variable 'x' not defined`; neither names a line, because Config::Grammar checks when the section closes.
- **Missing include (8).** SmokePing says `can't open X: No such file or directory` without a file or line. The PHP parser names the including file and line.
- **Unknown probe module (8).** SmokePing reports Perl's `require` failure. The PHP parser says no SmokePing probe module has that name, at the probe's line.
- **Include cycle (1).** SmokePing has no cycle check; it recurses until it runs out of file handles (`Too many open files`). The PHP parser refuses the include that closes the cycle, at its line.

## Timing

The 10,000-target configuration, wall time in the containers on one aarch64 host:

| | 2.7.3 | 2.8.2 | 2.9.0 |
|---|---|---|---|
| SmokePing's parser, with Perl start-up | 1.10 s | 1.17 s | 1.07 s |
| PHP parser, with PHP start-up | 0.07 s | 0.07 s | 0.07 s |
| PHP parser, parse and inventory only | 0.065 s | 0.065 s | 0.065 s |

The PHP figure is the same for every row because the PHP side does not depend on the distribution. The earlier figure for the Perl script was about 1.1 s. The poller no longer starts a process for the inventory, so the reader saves the Perl start-up too.

## Where the PHP parser differs on purpose

- **Checks that look at the host.** SmokePing checks that `piddir`, `imgcache`, `smokemail`, `tmail`, `template`, `secrets`, and each probe `binary` exist and have the right type or mode. The reader's host need not be SmokePing's, and the parser must not probe arbitrary paths named by a file, so the PHP parser checks only the form of those values. A configuration SmokePing refuses only because such a file is missing on the reader host is accepted.
- **One grammar for every release.** The probe variables come from the union of the 38 probe modules of 2.7.3, 2.8.2 and 2.9.0 (`lib/ProbeCatalog.php`, generated by `tools/make-probe-catalog.sh`), with the newest release's pattern where they differ (FPing and RemoteFPing `protocol`, SSH `keytype`). The InfluxDB section and the 2.9 Presentation colours are accepted on every release. A file using them is accepted where an older SmokePing would refuse it; the inventory is unaffected.
- **Limits SmokePing does not have.** Each file 16 MiB, all files 64 MiB, lines 64 KiB, 256 includes, includes 16 deep, sections 32 deep, 500,000 sections. An include must resolve, through symbolic links, inside the allowed directories, which default to the main file's directory and everything below it. An include cycle is refused. A file read on its own (an uploaded file) refuses `@include` and says so. None of the packaged or generated valid files comes near a limit.
- **Probe module code is not run.** SmokePing loads each probe module and calls its `probevars` and `targetvars`; the PHP parser uses the catalog instead and never runs anything named by the file.
