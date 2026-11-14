# Changelog

All notable changes to this plugin are recorded here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow [Semantic Versioning](https://semver.org/spec/v2.0.0.html). Each entry names the issues it closes or advances.

## [1.0.0-rc.1] - Unreleased

First release candidate of observe mode (phase 1). The plugin reads SmokePing's configuration and RRD files, shows their state, creates Cacti devices, data sources, and graphs for them, and feeds Thold and Maint. It never writes to SmokePing.

### Added

- Plugin lifecycle with a PHP 8.2 guard that leaves Cacti running on older PHP, tracked tables, hooks, two realms (SmokePing View and SmokePing Admin), and settings (#25).
- Inventory of SmokePing targets read by the plugin's PHP parser of SmokePing's configuration, checked against SmokePing 2.7.3, 2.8.2 and 2.9.0 by a differential test (decision 0009), an observation cache read from the RRD files by a reader process on the poller (#29), and a health page (#32).
- Cacti graph templates that draw SmokePing's smoke graph from its RRD files, compared against SmokePing's own graph definition (#17).
- Provisioning of devices, summary data sources, and smoke graphs for targets, one at a time or in bulk, with re-link proposals for renamed targets (#35, #36).
- A read-only mount of SmokePing's data directory inside Cacti's RRA path, checked by the health page, and a purge guard that keeps Cacti's cleanup away from SmokePing's files (decision 0007, #37).
- Summary data sources polled from the reader's cache for Thold (#38), and end-to-end tests of Thold and Maint on SmokePing data (decision 0002, #39).
- Export and import of the plugin's configuration as YAML (the default) or XML, from the command line and an admin page, with a confirmed plan and a transaction. An uninstall writes a YAML export first (decision 0008, #45).
- Install, operator, alerting, backup and restore, performance, and failure-qualification documentation (#43, #45, #42, #46).
- A release archive script that builds from the committed tree and ships only runtime files, the translation template, and the documentation (#44).

### Changed

- The version is semantic and recorded in `INFO` (#44).
- The plugin runs no Perl. The Perl Binary and SmokePing Perl Library Directory settings are removed, and the upgrade deletes them; an rc.1 configuration export that lists them still imports (#29).

### Not included

- Managed mode, where the plugin writes SmokePing's configuration, and SmokePing on Cacti remote data collectors. The configuration schema reserves the keys managed mode will use.
