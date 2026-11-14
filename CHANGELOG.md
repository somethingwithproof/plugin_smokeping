# Changelog

All notable changes to this plugin are recorded here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow [Semantic Versioning](https://semver.org/spec/v2.0.0.html). Each entry names the issues it closes or advances.

## [Unreleased]

## [1.0.0-rc.1] - 2026-11-14

First release candidate of observe mode (phase 1). The plugin reads SmokePing's configuration and RRD files, shows their state, creates Cacti devices, data sources, and graphs for them, and feeds Thold and Maint. It never writes to SmokePing.

### Added

- Plugin lifecycle with a PHP 8.2 guard that leaves Cacti running on older PHP, tracked tables, hooks, two realms (SmokePing View and SmokePing Admin), and settings (#25).
- Inventory of SmokePing targets read by the plugin's PHP parser of SmokePing's configuration, checked against SmokePing 2.7.3, 2.8.2 and 2.9.0 by a differential test (decision 0009), an observation cache read from the RRD files by a reader process on the poller (#29), and a health page (#32).
- Cacti graph templates that draw SmokePing's smoke graph from its RRD files, compared against SmokePing's own graph definition (#17).
- Provisioning of devices, summary data sources, and smoke graphs for targets, one at a time or in bulk, with re-link proposals for renamed targets (#35, #36).
- A read-only mount of SmokePing's data directory inside Cacti's RRA path, checked by the health page, and a purge guard that keeps Cacti's cleanup away from SmokePing's files (decision 0007, #37).
- Summary data sources polled from the reader's cache for Thold (#38), and end-to-end tests of Thold and Maint on SmokePing data (decision 0002, #39).
- Export and import of the plugin's configuration as YAML (the default) or XML, from the command line and an admin page, with a confirmed plan and a transaction. An uninstall writes a YAML export first (decision 0008, #45).
- Import of an existing SmokePing configuration under Console > Import/Export and with `cli/smokeping.php import-smokeping`: a preview of the sections, devices, graphs, summary-only targets, graph tree and load, then a confirmed, repeatable apply through Cacti's device, graph and tree functions (#35).
- A SmokePing top tab for users with the SmokePing View realm (#26).
- Install, operator, alerting, backup and restore, performance, and failure-qualification documentation (#43, #45, #42, #46).
- A release archive script that builds from the committed tree and ships only runtime files, the translation template, and the documentation (#44).
- Releases are made by pushing a `v<version>` tag: the workflow checks the tag against `INFO` and this changelog, runs the whole CI for the tagged commit, builds the archive, attests its provenance and publishes the GitHub Release with the changelog section as its notes. The procedure is in `docs/RELEASING.md`.
- CI runs the end-to-end suites on Cacti 1.2.31 and 1.2.x against a MariaDB service container, the install guide's commands on Debian 13 and AlmaLinux 9, a workflow lint, and the release tools' tests. One job, `ci-ok`, needs them all. The test image is built from `tests/docker/Dockerfile`.
- A live suite, `tests/live/run-live-e2e.sh`, runs the plugin against a real SmokePing network on the release host.
- Each smoke graph's upper limit is sized from its file when the graph is created, as SmokePing sizes its graphs: 1.2 times the largest median of the last day. `cli/smokeping.php size-graphs` sizes them again. The template's 0.05 s limit left the graphs of targets slower than 50 ms empty.

### Changed

- The version is semantic and recorded in `INFO` (#44).
- The reader time budget, inventory interval and freshness allowance settings refuse values that are not whole numbers in range (#26).
- The plugin runs no Perl. The Perl Binary and SmokePing Perl Library Directory settings are removed, and the upgrade deletes them; an rc.1 configuration export that lists them still imports (#29).
- GitHub's automatic source archives hold the same files as the release archive: tests, tools and CI files are left out by `export-ignore`.
- The end-to-end runners read the network, database host, image and credentials from the environment, and fail when a check fails.

### Fixed

- Devices the plugin created also got a graph for each smoke template with no data source, which rendered as an RRDtool error: the SmokePing Target device template listed the graph templates. It lists none now, and an upgrade removes the old links.
- Graphs at the master location of an imported target were titled with the device's host name; every graph is titled for its target.

### Not included

- Managed mode, where the plugin writes SmokePing's configuration, and SmokePing on Cacti remote data collectors. The configuration schema reserves the keys managed mode will use.

[Unreleased]: https://github.com/somethingwithproof/plugin_smokeping/compare/v1.0.0-rc.1...HEAD
[1.0.0-rc.1]: https://github.com/somethingwithproof/plugin_smokeping/releases/tag/v1.0.0-rc.1
