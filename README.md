<!-- translations:start -->
[English](README.md) | [简体中文](docs/i18n/zh-CN/README.md) | [हिन्दी](docs/i18n/hi-IN/README.md) | [Español (España)](docs/i18n/es-ES/README.md) | [Español (Latinoamérica)](docs/i18n/es-419/README.md) | [العربية (السعودية)](docs/i18n/ar-SA/README.md) | [Afrikaans](docs/i18n/af-ZA/README.md) | [አማርኛ](docs/i18n/am-ET/README.md) | [العربية (مصر)](docs/i18n/ar-EG/README.md) | [العربية (المغرب)](docs/i18n/ar-MA/README.md) | [বাংলা](docs/i18n/bn-BD/README.md) | [Bosanski](docs/i18n/bs-BA/README.md) | [Deutsch](docs/i18n/de-DE/README.md) | [فارسی](docs/i18n/fa-IR/README.md) | [Filipino](docs/i18n/fil-PH/README.md) | [Français](docs/i18n/fr-FR/README.md) | [Français (Maroc)](docs/i18n/fr-MA/README.md) | [Français (Sénégal)](docs/i18n/fr-SN/README.md) | [Hausa](docs/i18n/ha-NG/README.md) | [עברית](docs/i18n/he-IL/README.md) | [Hrvatski](docs/i18n/hr-HR/README.md) | [Bahasa Indonesia](docs/i18n/id-ID/README.md) | [Igbo](docs/i18n/ig-NG/README.md) | [Italiano](docs/i18n/it-IT/README.md) | [日本語](docs/i18n/ja-JP/README.md) | [한국어](docs/i18n/ko-KR/README.md) | [Malagasy](docs/i18n/mg-MG/README.md) | [मराठी](docs/i18n/mr-IN/README.md) | [Bahasa Melayu](docs/i18n/ms-MY/README.md) | [Nederlands](docs/i18n/nl-NL/README.md) | [Polski](docs/i18n/pl-PL/README.md) | [Português (Brasil)](docs/i18n/pt-BR/README.md) | [Русский](docs/i18n/ru-RU/README.md) | [Kinyarwanda](docs/i18n/rw-RW/README.md) | [Soomaali](docs/i18n/so-SO/README.md) | [Српски](docs/i18n/sr-RS/README.md) | [Svenska](docs/i18n/sv-SE/README.md) | [Kiswahili](docs/i18n/sw-KE/README.md) | [தமிழ்](docs/i18n/ta-IN/README.md) | [తెలుగు](docs/i18n/te-IN/README.md) | [ไทย](docs/i18n/th-TH/README.md) | [Türkçe](docs/i18n/tr-TR/README.md) | [Українська](docs/i18n/uk-UA/README.md) | [اردو](docs/i18n/ur-PK/README.md) | [Tiếng Việt](docs/i18n/vi-VN/README.md) | [isiXhosa](docs/i18n/xh-ZA/README.md) | [Yorùbá](docs/i18n/yo-NG/README.md) | [繁體中文](docs/i18n/zh-TW/README.md) | [isiZulu](docs/i18n/zu-ZA/README.md)
<!-- translations:end -->

<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="docs/images/logo_dark.png">
    <img src="docs/images/logo.png" width="200" height="200" alt="SmokePing Cacti Plugin: a cactus wearing a badge with a SmokePing graph">
  </picture>
</p>

# SmokePing plugin for Cacti

[![CI](https://github.com/somethingwithproof/plugin_smokeping/actions/workflows/plugin-ci-workflow.yml/badge.svg)](https://github.com/somethingwithproof/plugin_smokeping/actions/workflows/plugin-ci-workflow.yml)
[![License: GPL-2.0-or-later](https://img.shields.io/badge/license-GPL--2.0--or--later-blue.svg)](LICENSE)
![PHP >= 8.2](https://img.shields.io/badge/php-%3E%3D8.2-777bb4.svg)
![Cacti >= 1.2.31](https://img.shields.io/badge/cacti-%3E%3D1.2.31-2e7d32.svg)
[![Quality Gate Status](https://sonarcloud.io/api/project_badges/measure?project=somethingwithproof_plugin_smokeping&metric=alert_status)](https://sonarcloud.io/summary/new_code?id=somethingwithproof_plugin_smokeping)
[![Coverage](https://sonarcloud.io/api/project_badges/measure?project=somethingwithproof_plugin_smokeping&metric=coverage)](https://sonarcloud.io/summary/new_code?id=somethingwithproof_plugin_smokeping)
[![Security Rating](https://sonarcloud.io/api/project_badges/measure?project=somethingwithproof_plugin_smokeping&metric=security_rating)](https://sonarcloud.io/summary/new_code?id=somethingwithproof_plugin_smokeping)
[![Reliability Rating](https://sonarcloud.io/api/project_badges/measure?project=somethingwithproof_plugin_smokeping&metric=reliability_rating)](https://sonarcloud.io/summary/new_code?id=somethingwithproof_plugin_smokeping)
![SmokePing: read-only](https://img.shields.io/badge/smokeping-read--only-6b7280.svg)

A Cacti 1.2 plugin that brings SmokePing latency and loss measurements into Cacti as ordinary devices, data sources, and graphs. Smoke graphs then sit next to the device they belong to, under Cacti's permissions, and Thold and Maint can alert on and suppress them. It is an independent implementation for Cacti and is not part of, or endorsed by, [SmokePing](https://oss.oetiker.ch/smokeping/), whose original authors are Tobias Oetiker and Niko Tyni. The plugin neither includes nor runs SmokePing's code: it reads the configuration and RRD files that SmokePing writes, and its graph templates are built to draw the same series as SmokePing's own graphs.

> **Status: in development. Observe mode (phase 1) is implemented on `main` (1.0.0-rc.1) with operator filtering, CSV export, orphan RRD detection, Thold alert translation, ecosystem integration hooks, and remote collector replication.** The plugin reads SmokePing's configuration and RRD files, shows their state, creates Cacti devices, data sources, and graphs for them, and feeds Thold and Maint. It writes nothing to SmokePing. Managed mode (the plugin writing SmokePing configuration) is a later phase, not started, and is not in this release. Pilots on real installations and a closer match to SmokePing's graph legend remain open in milestone 4. See [docs/install.md](docs/install.md) to start.

## Access roles

| Realm | Access |
| --- | --- |
| SmokePing View | Existing monitoring pages. |
| SmokePing Operator | Monitoring plus the Operations page and personal triage acknowledgments. |
| SmokePing Admin | Operator access plus the existing configuration and administrative actions. |

After copying upgrade files, open the Plugins page as an administrator to register
the new realm. Assign the Operator realm explicitly under **Configuration > Users** or user groups.
Upgrades retain the existing View and Admin realm IDs and grants. Admin access
inherits Operator access, and Operator access inherits View access on each request;
this does not add stored grants to users or groups. Plugin settings also require
SmokePing Admin access in addition to Cacti's Settings permission.

Cacti device permissions still limit the rows and actions available to each user; unmapped objects require an administrator.
Credentials, configuration, mapping, deletion and other administrative changes
remain in the administrative pages. Acknowledgments last only in the current
user's session, for at most one hour and only while the same observation remains
fresh. New or changed observations invalidate them. They never mark a failed
measurement healthy or suppress alerts.

## Screenshots

Imported from a live SmokePing 2.8.2 network into Cacti 1.2.31, last 12 hours. Each target's device has a smoke graph for the master and one for each slave (`lon1`, `sgp1`), filed under its SmokePing section in the graph tree.

![Cacti tree view of the Bangalore target: smoke graphs from the master and the lon1 and sgp1 slaves](docs/images/screenshot-tree-asia.png)

![Cacti tree view of the London target: the lon1 slave in the same region shows microsecond latency with visible smoke](docs/images/screenshot-tree-europe.png)

## Why

SmokePing measures latency distribution and packet loss well, and its smoke graphs show variation that an average-only graph hides. Run alongside Cacti, it leaves three gaps:

- **Separate access control.** SmokePing's CGI knows nothing about Cacti users, groups, or device permissions.
- **No device context.** Latency and interface counters for the same device live in different tools.
- **No path into Cacti alerting.** Thold and Maint work on Cacti data sources and devices, so SmokePing alerts are configured and suppressed separately.

The plugin closes these gaps by representing each SmokePing target as Cacti objects that point at SmokePing's existing RRD files, plus a small set of polled summary values. It reuses Cacti's graphing, permissions, Thold, and Maint rather than building parallel versions of them. Poller-scoped configuration replication (`replicate_out`) pushes remote poller configuration tables while keeping local mode (poller 1) and collector-local observation caches isolated.

## Scope and status

| Phase | Scope | Milestones | Status |
|---|---|---|---|
| 0, research | Compatibility matrix, upstream behavior confirmation, graph fidelity spike | [M1](https://github.com/somethingwithproof/plugin_smokeping/milestone/1) | Findings in `docs/research`; some research issues are open |
| 1, observe | Inventory import, Cacti devices, smoke and summary graphs, Thold alerting and alert translation, Maint suppression, filtered views, CSV export, orphan RRD detection, ecosystem hooks | [M2](https://github.com/somethingwithproof/plugin_smokeping/milestone/2), [M3](https://github.com/somethingwithproof/plugin_smokeping/milestone/3), [M4](https://github.com/somethingwithproof/plugin_smokeping/milestone/4) | Implemented; pilots open |
| 2, managed | Cacti-owned configuration namespace, ICMP and TCP probe profiles, revisions, validation, reload, rollback | [M5](https://github.com/somethingwithproof/plugin_smokeping/milestone/5) | Planned, not started |
| 3, remote | Poller-scoped queries and `replicate_out` table replication for remote data collectors (`RemoteReplication`); full multi-collector SmokePing deployment qualification | [M6](https://github.com/somethingwithproof/plugin_smokeping/milestone/6) | Replication and poller scoping implemented; multi-collector deployment qualification open |

Implemented operator workflows, CLI commands, and ecosystem hooks:

- **Inventory and target filtering (`smokeping_inventory.php`, `smokeping.php`)**: `TargetFilter`, `TargetRepository::filteredForPoller()`, `InventoryPage`, and `ViewPage` provide search (`filter`), probe (`probe`), inventory presence (`presence`), mapping status (`mapping`), freshness state (`state`), and optional page-size pagination (`rows`: `All`, `25`, `50`, `100`, `200`). Device visibility is enforced in SQL before counting so totals never expose hidden devices.
- **CSV export (`smokeping.php?action=export_csv`)**: `CsvExporter` streams RFC 4180 CRLF output for the active filtered view under the caller's device permissions, prefixing cells starting with `=`, `+`, `-`, `@`, tab, or carriage return with an apostrophe to neutralize spreadsheet formulas.
- **Orphan RRD detection (`smokeping_health.php`)**: `OrphanRrdScanner` and `HealthCheck::orphanRrds()` perform a bounded scan of the SmokePing mount on the reader poller, skipping `__cgi`, `__sortercache`, and symbolic links, and cross-referencing unowned `.rrd` files with open re-link proposals.
- **SmokePing-to-Thold conversion (`smokeping_health.php`, `cli/smokeping.php`)**: `TholdTranslator` and `TholdGenerator` read SmokePing `*** Alerts ***` definitions and translate supported consecutive loss patterns into equivalent `loss_pct` Thold thresholds without modifying SmokePing configuration files. The web flow uses a GET preview (`action=thold_preview`, `HealthPage::renderTholdPreview()`) followed by a CSRF-protected POST (`action=thold_create`). Unsupported patterns (RTT alerts, wildcard decay, mixed levels) are skipped without writing.
- **Administrator CLI (`cli/smokeping.php`)**: supports `provision <target path> [--device=ID] [--location=NAME]`, `relink <old target path> <new target path> [--location=NAME]`, `thold-generate <target path> [--alert=NAME] [--apply]`, `size-graphs`, `upgrade-graphs`, `export [--format=yaml|xml] [--file=PATH]`, `import --file=PATH [--format=yaml|xml] [--apply --confirm=PLAN]`, and `import-smokeping [--dry-run | --apply --confirm=PLAN] [--file=PATH] [--sections=A,B/C] [--device-per=host|target] [--device-template=ID] [--poller=ID] [--site=ID] [--tree=ID | --tree-name=NAME]`.
- **Ecosystem hooks (`setup.php`, `Schema::HOOKS`)**: registers 21 hooks, including `abilities_register` (`AbilitiesProvider`: strictly read-only `smokeping-list-targets` and `smokeping-get-health`, View realm gated against the caller's user ID, returning no secrets), `doctor_register_checks` (`DoctorChecks`: `smokeping_stale_targets`, `smokeping_freshness_breaches`, `smokeping_orphan_rrds`, `smokeping_config_path`), `casc_register_providers` (`CascProvider`: `smokeping` YAML/XML `export`, `validate`, `plan`, and `apply` backed by `ConfigCodec` and `ConfigService`), and `replicate_out` (`RemoteReplication`: poller-scoped sync of `plugin_smokeping_target`, `plugin_smokeping_mapping`, `plugin_smokeping_object`, and `plugin_smokeping_relink` for remote pollers with `poller_id > 1`).

Out of scope: a plugin-owned alerting engine, a reporting engine, a custom daemon or network protocol, managed HTTP/DNS/service checks (servcheck covers them), and writing to SmokePing's RRD files.

## Compatibility targets

| Component | Target |
|---|---|
| Cacti | 1.2.31 or later in 1.2.x, no core patches |
| PHP | 8.2 or later; `setup.php` stays parseable on 8.0 so an unsupported runtime gets a refusal instead of a broken Cacti UI |
| SmokePing | 2.9.0, 2.8.2, and 2.7.3 (Ubuntu 22.04 only, until 2027-06-01) |
| Thold, Maint | No changes required |
| Series | 10,000 per installation at 20 pings and a 300 s step ([performance](docs/performance.md#supported-series-count)) |

## Documentation

| | |
|---|---|
| [Install](docs/install.md) | Requirements, the read-only mount of SmokePing's data directory, installation, containers, supported versions |
| [Operate](docs/operations.md) | What each state means and its evidence, health checks, behavior when inputs fail |
| [Add targets to Cacti](docs/provisioning.md) | One target, many devices, re-linking, permissions |
| [Alerting](docs/alerting.md) | Summary values, Thold durations, staleness, late data, overlap with SmokePing's alerts, Maint |
| [Configuration format](docs/configuration-format.md) | YAML and XML export and import |
| [Backup and restore](docs/backup-restore.md) | What to back up and how a restore resumes |
| [Performance](docs/performance.md) | Local and release-runner measurements at 100, 1,000, and 10,000 series, and the supported series count: 10,000 per installation |
| [Failure qualification](docs/failure-qualification.md) | The failure scenarios and how each is tested |
| [Releasing](docs/RELEASING.md) | The release procedure: the pull request, the signed tag, what the workflow does, how to verify the result |
| [Release archive](docs/release.md) | Version, changelog, and the release archive |
| [Testing](docs/testing.md) | The suites, what CI runs, and the live suite against a real SmokePing |

## Design

- [docs/PLAN.md](docs/PLAN.md) is the full plan: architecture, upstream facts it depends on (with sources), state model, security boundaries, performance arithmetic, testing, and risks.
- [docs/decisions/](docs/decisions/) holds architecture decision records for choices already made.
- [docs/REQUIREMENTS.md](docs/REQUIREMENTS.md) maps every requirement ID to its issue.
- Local verification runs Pest unit and source-rule checks (`pest --configuration=phpunit.xml`), PHPStan Level 9 (`CACTI_PATH=<cacti-dir> phpstan analyse -c phpstan.neon --no-progress`), entry-point syntax checks (`php -l` on `*.php`, `include/*.php`, `scripts/*.php`, `cli/*.php`), and translation checks (`sh locales/build_gettext.sh --check`). See [docs/testing.md](docs/testing.md) for the integration and container end-to-end suites.

```mermaid
flowchart LR
    SP[SmokePing daemon] --> RRD[(RRD files)]
    RRD -->|read-only| R[Reader on the adjacent Cacti poller]
    RRD -->|read-only| G[Cacti graph templates]
    R --> SS[Script server summary data sources]
    SS -->|poller_output| T[Thold and Maint]
    G --> P[Cacti graph permissions]
```

## How work is tracked

- **Milestones** follow the plan's milestones. Each has an epic issue listing its sub-issues and exit evidence.
- **Issues** are typed by label: `type: requirement`, `type: research`, `type: decision`, `type: epic`. Requirement issues state numbered, testable requirements (`REQ-AREA-NNN`) and acceptance criteria.
- **Labels** also carry `area:`, `phase:`, `priority:`, and `status:`. See [CONTRIBUTING.md](CONTRIBUTING.md#labels).
- The [project board](https://github.com/users/somethingwithproof/projects/6) shows all issues across milestones.

## Contributing

Read [CONTRIBUTING.md](CONTRIBUTING.md). Commits need a DCO sign-off. Design discussion happens on issues; open decisions carry the `type: decision` label.

## Security

Report vulnerabilities privately; see [SECURITY.md](SECURITY.md).

## License

GNU General Public License v2.0, the same license as Cacti and its plugins. See [LICENSE](LICENSE).
