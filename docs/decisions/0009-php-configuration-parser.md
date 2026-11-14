# 0009. Read SmokePing's configuration with a PHP parser

Status: Accepted (2026-11-13). Supersedes the inventory-script part of the original design (docs/PLAN.md, "Inventory script"; [research](../research/inventory-script.md)).

## Context

The reader learned SmokePing's targets from `bin/smokeping-inventory.pl`, a Perl script that loaded `Smokeping.pm` and ran `Smokeping::get_parser()`. That was the most faithful reading possible, but it meant:

- Every reader host needed Perl, SmokePing's Perl library, and two settings (Perl Binary, SmokePing Perl Library Directory) pointing at them, with a different library path per distribution.
- The poller ran code from SmokePing's installation, including each probe module's `probevars`, in Cacti's poller.
- A container running Cacti needed SmokePing installed in it.
- An operator could not hand the plugin a configuration file to import without that file being parsed by a Perl process on the server.

The project has decided that everything the plugin runs is PHP, and that an operator can import an existing SmokePing configuration into Cacti, including an uploaded one.

## Decision

- The plugin reads SmokePing's configuration with its own parser, `SmokepingConfigParser`, which implements Config::Grammar with SmokePing's grammar for what the inventory needs. `SmokepingInventory` turns the parsed tree into the same inventory document the script printed, and `SmokepingConfigReader` is the reader's `InventoryReader`.
- Probe and target variables of SmokePing's probe modules are a generated table, `lib/ProbeCatalog.php`, built by `tools/make-probe-catalog.sh` from the modules of every supported release. No probe module code runs.
- The file is untrusted input: size, line length, nesting, include count and depth are bounded, includes must resolve inside the allowed directories (the main file's directory by default), cycles are refused, and errors name the file and line.
- The Perl script moves to `tests/oracle/` and is used only as the oracle of a differential test, `tests/oracle/run.sh`, against SmokePing 2.7.3, 2.8.2 and 2.9.0. The parser is accepted only while that test passes: the same inventory for every valid file, and a refusal naming the same line for every broken file where SmokePing names one.
- The Perl Binary and SmokePing Perl Library Directory settings are removed; schema 3 deletes them on upgrade.

## Consequences

- Installing the plugin needs no Perl, no SmokePing library, and no group membership on packaged SmokePing. A container running Cacti needs only the configuration directory and the data mount.
- The inventory of 10,000 targets takes 0.07 s instead of about 1.1 s, and no process is started for it.
- The plugin maintains a parser of another project's format. A new SmokePing release, a new probe module, or a grammar change needs the catalog regenerated and the differential test rerun before it is supported. The test is in CI.
- The parser does not check that files SmokePing's grammar names (piddir, probe binaries) exist on the reader host. A file SmokePing would refuse only for that reason is read; SmokePing's own `--check` remains the operator's validation.
- The same parser reads an uploaded file for the import page, with `@include` refused.

## Alternatives rejected

- **Keep the Perl script.** Contradicts the PHP-only decision and keeps the per-distribution library setup.
- **Run `smokeping --check` or `smokeinfo` and parse its output.** Still Perl, still SmokePing's code in the poller, and neither prints the probe, step and pings the inventory needs.
- **Walk the data directory for RRD files.** Gives paths but not hosts, probes, step or pings, and picks up non-target files (Debian's `__cgi`, Alpine's `images`); see the distribution matrix.
- **A general Config::Grammar port.** The plugin needs one grammar; a general engine adds code the differential test would not cover.
