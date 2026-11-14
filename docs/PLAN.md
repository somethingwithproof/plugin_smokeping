# Cacti 1.2 SmokePing Plugin Plan

Status: proposal, revision 3 (2026-11-13). Nothing described here as a plugin capability exists yet. Statements about SmokePing, Cacti core, Maint, Thold, and servcheck describe current upstream behavior and cite the file that was checked. Owner and estimates are not assigned; milestone 1 produces the estimates.

`plugin_smokeping` brings SmokePing latency and loss measurements into Cacti 1.2 as ordinary Cacti devices, data sources, and graphs, so operators see smoke graphs next to the device they belong to, under Cacti's own permissions, and alert on them with Thold and Maint.

## Why this plugin

SmokePing measures latency distribution and packet loss well, and its smoke graphs show variation that an average-only graph hides. [Graph interpretation](https://oss.oetiker.ch/smokeping/doc/reading.en.html). Running it next to Cacti today leaves three gaps:

1. **Separate access control.** SmokePing's CGI has its own authentication and no notion of Cacti users, groups, or device permissions.
2. **No device context.** Latency is investigated in one tool and interface counters for the same device in another, with no link between them.
3. **No path into Cacti alerting.** Thold evaluates Cacti data sources, and Maint suppresses Cacti devices. SmokePing alerts are configured, routed, and suppressed separately.

The Cacti GitHub organization has no SmokePing plugin today (checked 2026-11-13).

The plugin closes these gaps by representing each SmokePing target as Cacti objects that point at SmokePing's existing RRD files, plus a small set of polled summary values. It reuses Cacti's graphing, permissions, remote data collectors, Thold, and Maint instead of building parallel versions of them. It does not replace SmokePing's collector, its storage, or its master/slave protocol.

## Goals and non-goals

**Goals**

- Faithful smoke graphs rendered by Cacti's own graph engine from SmokePing's RRD files, checked against SmokePing's rendering of the same data.
- Cacti device and graph permissions on everything, with no plugin-specific permission layer for graphs.
- Alerting and maintenance through Thold and Maint, so one notification path covers SNMP and SmokePing data.
- Read-only operation against an existing SmokePing installation first; Cacti-managed configuration later, in a namespace that cannot touch hand-maintained targets.
- Remote SmokePing hosts reached through Cacti's remote data collectors, not a new network service.
- No required Cacti core patches.

**Non-goals**

- A plugin-owned incident, escalation, or notification engine.
- A reporting engine. Long-range service reports are research.
- A custom network protocol, daemon, or credential store.
- Managed HTTP, DNS, mail, or directory checks. servcheck already provides them, including from remote data collectors.
- Writing to SmokePing's RRD files, or replacing SmokePing's collector or master/slave protocol.
- A public HTTP API or CLI in the first releases.

## Release phases

Each phase ships to users. Later phases are re-estimated after the phase before them.

| Phase | Label | Scope | Depends on |
|---|---|---|---|
| 0 | Research | Compatibility matrix, Cacti and SmokePing behavior confirmation, graph template fidelity spike (milestone 1) | Nothing |
| 1 | Planned | Observe mode on a SmokePing installation local to a Cacti poller: inventory import, devices, smoke graphs, summary data sources for Thold, Maint suppression, health page | Phase 0 |
| 2 | Planned | Managed mode: Cacti-owned configuration namespace, ICMP and TCP probe profiles, revisions, validation, reload, rollback | Phase 1, security review |
| 3 | Planned | Remote and distributed: SmokePing on a Cacti remote data collector, slaves as measurement locations | Phase 2 |
| - | Research | Coverage policies, service reports, public API and CLI, target-level maintenance fields, end-to-end canary, preserving history across observed-target renames | Demand after phase 1 |

Summary data sources were a separate phase in revision 2. They need no Thold change, so they move into phase 1.

## Compatibility

**Cacti.** Minimum Cacti release is 1.2.31, with no core patches. The plugin uses only APIs present in that release:

- Plugin lifecycle and hooks: `api_plugin_register_hook()`, `api_plugin_register_realm()`, tracked plugin tables ([lib/plugins.php](https://github.com/Cacti/cacti/blob/release/1.2.31/lib/plugins.php)).
- Devices and permissions: `api_device_save()` (`lib/api_device.php`), `is_device_allowed()` and `get_allowed_devices()` (`lib/auth.php`).
- Templates: `import_xml_data()` (`lib/import.php`).
- RRD access: the `path_rrdtool` setting. `rrd_init()` in 1.2.31 opens a write-only pipe (`popen(..., 'w')`), so it cannot return `lastupdate` output; the reader runs `rrdtool -` itself through `proc_open()` with both pipes.
- Background work: `exec_background()` and `register_process_start()` (`lib/poller.php`).
- Remote collectors: `call_remote_data_collector()` (`lib/functions.php`) and the `remote_agent` hook (`remote_agent.php`).

Milestone 1 confirms each signature at 1.2.31 and records any difference on the current `1.2.x` branch.

**PHP.** The plugin requires PHP 8.2 or later, matching the Cacti 1.2.x floor. The `1.2.x` branch declares `"php": ">=8.2"` and pins Composer resolution to `platform.php: 8.2.0` ([branch composer.json](https://github.com/Cacti/cacti/blob/1.2.x/composer.json), Cacti/cacti#8569). PHP 8.0 and 8.1 are not supported, even on a 1.2.31 installation whose `composer.json` still says `>=8.0` ([release composer.json](https://github.com/Cacti/cacti/blob/release/1.2.31/composer.json)).

Because Cacti includes plugin files on every request, a parse error in a plugin breaks the whole Cacti UI, not only the plugin. A 1.2.31 site can still run PHP 8.0 or 8.1, so:

- `setup.php`, `INFO`, and every file Cacti includes before the version check use only PHP 8.0 syntax.
- `plugin_smokeping_check_config()` and `plugin_smokeping_install()` refuse to proceed when `version_compare(PHP_VERSION, '8.2.0', '<')`, with a message naming the required version.
- Hook shims return early under the same condition, before the autoloader is registered, so no class file is ever parsed on an unsupported runtime.
- CI lints `setup.php` and the shim files under PHP 8.0.

Code loaded after the check uses PHP language features through 8.2 where they make the code clearer:

- **8.0:** `match`, the nullsafe operator, named arguments, constructor property promotion, union types, `mixed`, `str_contains()`, `str_starts_with()`, `str_ends_with()`.
- **8.1:** enums, `readonly` properties, `never`, first-class callable syntax, `array_is_list()`.
- **8.2:** `readonly` classes, DNF types, and the standalone `null`, `true`, and `false` types.

Features from PHP 8.3 and later are not used: typed class constants, `#[\Override]`, and `json_validate()`. Properties are always declared, because PHP 8.2 deprecates dynamic properties. PHPStan runs at `phpVersion` 8.2. Core's modernization ladder governs changes to Cacti core, not new plugin code; plugin code still calls core helpers such as `cacti_sizeof()` where core does. No upper PHP bound is declared; the CI matrix defines the tested ceiling, and each new PHP minor is added at its release candidate.

**SmokePing.**

| SmokePing | Released | Status in matrix |
|---|---|---|
| 2.9.0 | 2025-02-19 | Required: EL 9 (EPEL), Fedora 43 to 45, Alpine 3.22 to 3.24 |
| 2.8.2 | 2021-08-10 | Required: Debian 13, Ubuntu 24.04, 26.04, 26.10 |
| 2.7.3 | 2018-12-20 | Required until 2027-06-01: Ubuntu 22.04 only |

Release dates are from the [SmokePing releases page](https://github.com/oetiker/SmokePing/releases). The [distribution matrix](research/distribution-matrix/README.md) records the packaged version, service user, file layout, file modes, and reload mechanism on each distribution with full vendor support (observed 2026-11-13). EL 10 and openSUSE Leap 16.0 have no vendor package, so they are supported only to the extent a source install is, which the matrix does not cover.

**Plugin metadata.** `INFO` declares `compat = 1.2.31` and `capabilities = online_view:1, online_mgmt:1, offline_view:0, offline_mgmt:0, remote_collect:1`, the same capability set servcheck and webseer declare.

## Upstream behavior the design depends on

Each row is a milestone 1 test, because a change in any of them changes the design.

**SmokePing**

| Fact | Consequence | Source |
|---|---|---|
| A target's RRD path is its tree position: `datadir/Section/Sub/target.rrd`. | Tree position is identity; moving or renaming a node creates a new, empty RRD. | [Configuration](https://oss.oetiker.ch/smokeping/doc/smokeping_config.en.html) |
| Section names must match `[-_0-9a-zA-Z]+` (dots are allowed only in `slaves=` values); `menu` and `title` default to `host`. | Display names can live in `menu`/`title` while the section name stays a stable ID. | Configuration; `lib/Smokeping.pm` |
| RRD data sources are `uptime`, `loss`, `median`, `ping1` to `pingN`, each GAUGE with heartbeat `2 × step`; `loss` maximum is `pings`. | Freshness and complete loss are readable from the RRD alone. | `lib/Smokeping.pm` (RRD create) |
| `step` and `pings` are fixed at RRD creation. | Changing either needs a migration, not an edit. | Configuration |
| Slave results go to `target~slave.rrd`. | Each location has its own RRD and freshness. | [Master/slave](https://oss.oetiker.ch/smokeping/doc/smokeping_master_slave.en.html) |
| Slaves cache undelivered results and resend them; the master applies them in timestamp order. | Data for a past period can arrive late. | Master/slave; `lib/Smokeping/Slave.pm` (cache and resend), `lib/Smokeping.pm` (ordering) |
| Master/slave messages are signed with MD5 over the message and a shared secret; TLS is optional. | The plugin cannot strengthen this channel; it documents TLS. | Master/slave |
| `Smokeping::get_parser()->parse($file)` parses a config outside the daemon; `smokeinfo` uses it. | Inventory can reuse SmokePing's parser from a short CLI script. No daemon is needed. | `lib/Smokeping/Info.pm` |
| The config grammar supports `@include filename`. | A Cacti-owned file can hold managed targets. | Configuration |
| `smokeping --config=x --check` validates a config; `--reload` reloads the running daemon. `--check` also tests the host: it fails when `piddir` or a referenced file such as `sendmail` is missing. `--reload` signals the PID in `piddir/smokeping.pid`, which the EL and Fedora units never write because they run `--nodaemon`. | Validation can run against a candidate without touching the live file, but a failing check may be an environment error. Reload goes through `systemctl reload`, not `--reload`. | [Command reference](https://oss.oetiker.ch/smokeping/doc/smokeping.en.html); [distribution matrix](research/distribution-matrix/README.md) |

**Cacti core 1.2.31**

| Fact | Consequence | Source |
|---|---|---|
| At 1.2.31, `get_data_source_path()` uses an absolute `data_source_path` unchanged. On `1.2.x` since eef6bb07e (GHSA-9qff, 2026-09-24) and on `develop`, `data_source_path_within_rra()` replaces any path outside `rra_path`, or through a symbolic link, with the generated path. A read-only bind mount at `<rra_path>/smokeping` passes (tested 2026-11-13). Data templates allow the "None" input method. | Smoke data sources use `<path_rra>/smokeping/...` through a read-only mount ([ADR 0007](decisions/0007-read-only-mount-under-rra-path.md)). Cacti never polls or writes them. | `lib/functions.php`, `data_templates.php` |
| `poller_maintenance.php` purges or archives RRD files queued when a data source is deleted or chosen on the RRD cleaner page. On failure it logs a warning and drops the queue entry. The RRD cleaner walks `rra_path` recursively. | Paths under the mount are purge targets. The read-only mount is the protection. Cacti queues the purge row after the `data_source_remove` and `device_remove` hooks run, so the plugin's hooks replace the path of its data sources with one that names no file, and a sweep at `poller_bottom` removes rows queued by other routes. Unmapped SmokePing RRDs show as unused on the RRD cleaner page. | `poller_maintenance.php` (`remove_files`), `rrdcleaner.php`, `lib/api_data_source.php` |
| With `storage_location` set to an RRDproxy, RRD paths resolve on the proxy host. | SmokePing paths do not exist there; the plugin refuses to create smoke data sources in that mode. | `lib/rrd.php`, `poller_maintenance.php` |
| Thold receives values through the `poller_output` hook. | Only polled data sources reach Thold. "None" data sources never do. | plugin_thold `setup.php` |
| On a remote data collector, CLI processes use the local database by default; web requests use the main database. When the main database is unreachable, the connection mode is `offline`; it is `recovery` while the local `poller_output_boost` table still holds rows to replay. | Remote CLI jobs must be explicit about which database they write and must work in both offline and recovery modes. | `include/global.php` |
| The `remote_agent` hook dispatches unknown actions to plugins; callers are authorized by matching their address to an enabled poller's hostname. | The main server can call plugin actions on a remote collector with Cacti's existing authorization. The authorization is address-based, not cryptographic. | `remote_agent.php` |
| `call_remote_data_collector()` accepts only relative URLs and uses the configured http or https scheme. | Plugin actions are plain relative requests. | `lib/functions.php` |

**Confirmed live in milestone 3** (Cacti 1.2.31 and the `1.2.x` branch at e2d5c2589, which reports 1.2.32; PHP 8.2; `tests/run-objects-e2e.sh`):

| Fact | Result | Evidence |
|---|---|---|
| A graph renders from a "None" input data source at `<path_rra>/smokeping/...` | Yes on both. On `1.2.x`, `get_data_source_path()` returns the mount path unchanged, which `SmokeProvisioner` checks before it reports success. | `graph-renders`, `data-source-path` |
| The poller and boost ignore it | Yes. Input method None creates no `poller_item` rows. | `not-polled` |
| Cacti's purge reaches the mount path | Yes, as designed. With the plugin disabled and a writable mount, deleting the device and running `poller_maintenance.php --force` deleted the RRD. A read-only mount alone kept it byte-identical. | `control-files-lost-method1`, `readonly-mount-alone-protects-method1` |
| `data_source_remove` and `device_remove` run before the purge row is queued | Yes, so the hooks disarm the path instead of deleting a row. With the hooks active, a writable mount lost no file under deletion with delete or archive. | `files-unchanged-method1`, `files-unchanged-method3` |
| The RRD cleaner page can queue a SmokePing file | Yes. On a read-only mount the delete failed and left the file. On a writable mount it deleted the file. | `rrdcleaner-queued-file-unchanged`, `rrdcleaner-on-writable-mount-is-unprotected` |
| rrdcheck leaves RRD files unchanged | Yes (`poller_rrdcheck.php --force`). | `rrdcheck-unchanged` |
| `create_complete_graph_from_template()` reuses a device's data source for a data template | Yes, unless the suggested values differ. A different `data_source_path` creates a second data source, which is how an archived data source sits beside the live one. | `relink-both-graphable` |
| `1.2.x` refuses a graph template export without `<inputs>` | Yes: "Graph template import refused invalid input relationships" (`lib/graph_template_input.php`). The shipped templates carry one graph template input. | `tests/Integration/LifecycleTest.php` on `1.2.x` |
| `create_complete_graph_from_template()` returns an array, not a graph id | Yes: `['local_graph_id' => ..., 'local_data_id' => [...]]`. | `lib/template.php` |
| Thold receives script-server values through `poller_output` | Yes. `poller.php --force` runs the summary data source through the script server, the `poller_output` hook hands the value to Thold, and `thold_data.lastread` equals the loss the fixture wrote (40 for 8 of 20 pings). plugin_thold 1.8.3 and plugin_maint 1.3 from the plugin workspace, Cacti 1.2.31. | `thold-receives-script-server-values`, `loss-value-reaches-thold` in `tests/alert-e2e.sh` |
| A Thold threshold on `loss_pct` triggers and recovers; one on `age` triggers when the collector stops | Yes, with no Thold change: one alert notification after the trigger count (2 polls), none repeated, one restoral; `age` fired at 103 s against a 90 s threshold after the fixture stopped, and its restoral followed when rounds resumed. | `thold-triggers-on-loss`, `thold-recovers-on-loss`, `thold-triggers-on-age`, `thold-recovers-on-age` |
| A Maint device schedule suppresses the notification while measurements continue | Yes. During the schedule three breaching polls produced no notification, `lastread` kept updating, and the summary RRD was written; Thold does not count breaches in maintenance, so the alert fires after the trigger count once the schedule ends. | `maint-suppresses-notification`, `measurements-continue-in-maint`, `alert-after-maint` |
| An unknown value raises no Thold alert, and `age` still does | Yes. On a series whose round was three steps old, `loss_pct` was `U` and raised nothing, while `age` (902 s) fired. | `stale-loss-unknown-no-alert`, `stale-age-alerts` |
| `import_xml_data()` imports a data input method from a plugin | Only with `lib/utility.php` loaded first: the import calls `update_replication_crc()` there. `TemplateInstaller` includes it. | `tests/Integration/LifecycleTest.php` |
| The summary script server input is fast enough at scale | A call took a mean 0.031 ms and a 99th percentile of 0.043 ms over 5,000 random series in a 10,000 row cache (one process, as the script server runs it); a cold command line call including the Cacti bootstrap took about 30 ms. These are local aarch64 container measurements (MariaDB 10.11, PHP 8.2 in Docker on one host), not a benchmark of a production poller. | `tests/bin/summary-bench.php` |
| Core `__()` formats a translated string with `sprintf()` and passes it the text domain | `sprintf(__('%d s', 'smokeping'), $n)` prints `0 s`: the placeholder takes `smokeping`. The values go inside the call, `__('%d s', $n, 'smokeping')`. A string with only positional placeholders (`%1$d`) is returned unformatted, because core's pattern does not match them. Found when the bulk action's confirm screen read "0 targets match"; fixed across the plugin and guarded by `tests/Security/SourceRulesTest.php`. | `include/global_languages.php`, `tests/permissions-e2e.sh` |
| A user is scoped to devices only when every permission policy denies | Cacti's default method is permissive device permissions: a user with device policy "deny" and device A allowed still saw every device while the graph and template policies allowed them. The permission suite sets all three policies to deny for its scoped users. | `tests/bin/permission-setup.php` |
| A denied graph request answers with an image, not an error | `graph_image.php` returns a small SVG reading "GRAPH ACCESS DENIED", so a test must look for graph content, not for an image. | `tests/permissions-e2e.sh` |
| Device permissions take effect on the next request | Yes. A user logged in with device A removed an instant before the next request lost the SmokePing page rows and both graphs of A with no new login. | `revoked-view-after`, `revoked-graph-after` |
| The bulk "Add SmokePing Targets" device action handles 100 devices in one request | Yes. 100 devices, 100 targets matched by host name, 100 summary and 100 smoke data sources and graphs in one `host.php` POST: 23.8 s on a local aarch64 container (PHP 8.2, MariaDB 10.11, built-in server). The default web limit is 30 s, so the action raises its own limit to 300 s and stops starting devices 60 s before that; running it again finishes the rest. | `bulk-action-100-devices` |
| `graph_buttons` hook arguments | `$data[1]` holds `local_graph_id`; the thumbnail view calls `graph_buttons_thumbnails` with the same payload, so the plugin registers both. | `graph-button-on-mapped-device`, `graph-button-absent-on-other-device` |

Core 1.2.31's `lib/snmpagent.php` includes `plugins/maint/functions.php`, which plugin_maint 1.3 moved to `includes/functions.php`. Every poll logs two PHP warnings for it. They do not affect Thold or Maint and come from the core and Maint versions, not this plugin.

Still to verify (not part of milestone 3): remote collector offline and recovery modes; `remote_agent` authorization.

**Maint and servcheck**

| Fact | Consequence | Source |
|---|---|---|
| Maint keys schedules by type: 1 Cacti device, 2 WebSeer URL, 3 servcheck test. Schedules store a name, type, start and end as Unix timestamps, and an interval. | Device maintenance works unchanged. Target-level maintenance, reasons, tickets, owners, and timezones need Maint changes. | plugin_maint `includes/functions.php`, `includes/database.php` |
| servcheck runs HTTP(S), REST API, DNS, DoH, SMTP, IMAP, POP3, LDAP, FTP, TFTP, SCP, SFTP, SSH command, SMB, MQTT, and SNMP checks from remote collectors. | Managed mode covers only ICMP and TCP. | plugin_servcheck `includes/arrays.php` (default branch `main`) |

## Architecture

```mermaid
flowchart TB
    subgraph Main["Cacti main server"]
        UI[Plugin pages] --> PERM[Cacti realms and device permissions]
        GR[Cacti graphs] --> PERM
        DB[(Cacti database: devices, data sources, plugin tables)]
        THOLD[Thold and Maint]
    end
    subgraph Poller["Cacti poller next to SmokePing: main server or remote collector"]
        PB[poller_bottom] --> RJ[poller_smokeping.php reader]
        RJ --> INV[Inventory script using SmokePing parser]
        RJ --> PIPE[Cacti rrdtool pipe]
        SS[Script server input] --> CACHE[(Observation cache)]
        RJ --> CACHE
        AP[Config writer] --> INC[Cacti-managed include file]
        AP --> REQ[Reload request file]
        RA[remote_agent actions]
    end
    subgraph SP["SmokePing"]
        D[SmokePing daemon] --> RRD[(RRD files, read-only to Cacti)]
        CFG[Main config] -->|@include| INC
        PU[systemd path unit] -->|reload| D
        REQ --> PU
    end
    SLAVES[SmokePing slaves] --> D
    PIPE --> RRD
    GR -->|reads through ro mount under rra_path| RRD
    SS -->|poller_output| THOLD
    UI -->|remote hosts| RA
```

SmokePing always runs on, or shares a filesystem with, a Cacti poller: the main server or a remote data collector. That poller does all SmokePing work. The main server never talks to SmokePing directly.

| Component | Responsibility | Interfaces | Failure behavior |
|---|---|---|---|
| Plugin pages | Inventory, mapping, health, revisions | Cacti page framework, csrf-magic, realms | Show cached data with its age; never block on SmokePing |
| Cacti graphs | Smoke graphs and summary graphs | Graph templates shipped as template XML | Standard Cacti behavior for a missing or unreadable RRD |
| Reader (`poller_smokeping.php`) | Run inventory, read `lastupdate` and bounded fetches through one rrdtool pipe, update cache and health | `poller_bottom`, `exec_background()`, `register_process_start()`, filtered by `poller_id` | Single instance per poller; time budget per run; resumes next cycle |
| Inventory script | Emit target path, `host`, `probe`, `menu`, `title`, `slaves`, `step`, `pings` as JSON | Perl CLI using `Smokeping::get_parser()`, run by the reader | Parse failure leaves the previous inventory in place and raises a health error |
| Script server input | Return `median`, loss percentage, and data age per target and location | Cacti script server | `U` for median and loss when stale; age always numeric |
| Config writer (phase 2) | Write the managed include file, run `--check`, request reload, verify | Files only | Failed check or verification restores the previous file and requests reload again |
| Path unit (phase 2) | Run the package's reload when a request file appears | systemd `.path` and `.service` units, installed by the administrator from shipped examples | Writes a result file the writer reads; no input from Cacti beyond the file's existence |
| `remote_agent` actions (phase 3) | Inventory, health, and apply on a remote collector | `remote_agent` hook, `call_remote_data_collector()` | Collector unreachable means data shows as stale, never healthy |

### Topologies

1. **SmokePing on the Cacti main server** (phase 1). The poller user reads `datadir` directly: packaged RRD files are `0644` in `0755` directories on every matrix distribution. Where an administrator has tightened those modes, group membership provides read access.
2. **SmokePing datadir shared with the main server** (phase 1). `datadir` is mounted read-only on the main server. Milestone 1 measures NFS attribute caching delay, and the health page compares `lastupdate` with the clock.
3. **SmokePing on a Cacti remote data collector** (phase 3). The collector runs the reader, inventory, script server input, and config writer. Summary values reach the main server through the normal poller output path. Smoke graphs need the RRD files readable on the main server, because Cacti renders graphs there. Without a shared filesystem, remote targets get summary graphs only. rrdcached is research.

The cost of topology 3 is a full Cacti remote collector (PHP, web server, local database) on the SmokePing host. It replaces a new plugin-specific daemon and credential store.

## Cacti objects

Each SmokePing target and location becomes ordinary Cacti objects created through Cacti APIs:

| Object | Created from | Notes |
|---|---|---|
| Device | "SmokePing Target" device template; the plugin sets availability None (Cacti 1.2.31 keeps availability on the device, not the template) when it creates the device, assigned to the poller next to SmokePing | Existing devices can be mapped instead; created only on operator confirmation |
| Smoke data source | "SmokePing Smoke (N pings)" data template, input method None, `data_source_path` `<path_rra>/smokeping/<Section>/<target>.rrd` through the read-only mount | Never polled; Cacti only reads it |
| Summary data source | "SmokePing Summary" data template using the script server input | Polled; reaches Thold through `poller_output` |
| Graphs | "SmokePing Smoke (N pings)" and "SmokePing Summary" graph templates | Placed in graph trees and protected by graph permissions like any Cacti graph |

Templates ship as Cacti template XML in the plugin and are imported with `import_xml_data()` at install and upgrade, matched by template hash so re-imports update rather than duplicate. Because `pings` is fixed per RRD and each `pingN` is a separate data source, there is one smoke template per supported `pings` value. The default set covers 10, 20, and 30; milestone 1 checks which values the supported distributions' default configs use.

Devices created by the plugin appear in device lists and in plugins such as monitor and Thold. They carry the "SmokePing Target" template so operators can filter them.

**Protecting SmokePing's files.**

- SmokePing's `datadir` is mounted read-only at `<rra_path>/smokeping` ([ADR 0007](decisions/0007-read-only-mount-under-rra-path.md)). Cacti's purge and RRD cleaner reach these paths, and the read-only mount makes every write, rename, and delete fail. The install guide documents the mount, and the health page reports an error when the mount path is missing, outside `rra_path`, reached through a symbolic link, or writable.
- The plugin's `device_remove` and `data_source_remove` hooks cannot delete a queued purge entry, because Cacti queues it after the hooks run (`lib/api_data_source.php`). They replace the path of the plugin's data sources with `<path_rra>/smokeping-removed/<id>.rrd`, which names no file, so the row Cacti queues cannot reach a SmokePing RRD. A sweep at `poller_bottom` on poller 1 also removes queued rows under the mount, such as those the RRD cleaner page creates. This is a second layer; the read-only mount is the first, and the only one that protects against an administrator queueing a file by hand on a writable mount.
- A release test deletes a plugin-created device with "delete data sources" selected and confirms the SmokePing RRD is unchanged.
- When `storage_location` points at an RRDproxy, the plugin creates summary data sources only and says why.

## Target identity and inventory

Every target record has a plugin ID, its SmokePing tree path, poller, probe, `step`, `pings`, locations, Cacti device, optional interface association, site, tags, and mode (observed or managed).

**Managed targets** (phase 2) live in one top-level section that only Cacti writes, `+ cacti`, included from the operator's main file with `@include`. Each target's section name is a random 12-character base36 string prefixed with `t`, for example `++ t8k2m0q4x7ra1`. IDs are generated once and never derived from an auto-increment column, so a reinstall or database restore cannot reissue an ID that already has an RRD. The config writer refuses to create a managed target whose RRD path already exists. The human name goes in `menu` and `title`; renaming is a label change and never moves the RRD file. The cost is a flat namespace in SmokePing's own CGI menu for managed targets; Cacti trees provide grouping. Ten thousand RRD files in one directory is within normal limits for ext4 and XFS.

**Observed targets** are identified by tree path. When an import finds a path missing and a new path with the same `host` and `probe`, the plugin proposes re-linking the Cacti device and leaves both records in place until an operator confirms. It never re-links automatically. A re-link repoints the smoke data source to the new RRD; history in the old file stays readable through a second, archived data source.

**Unmapped targets** are visible only to administrators until mapped to a device.

The plugin's `api_device_save` and `device_remove` hooks keep mappings consistent when devices are edited or deleted in Cacti, following the pattern in plugin_monitor.

## Measurement state

| Dimension | States | Evidence |
|---|---|---|
| Freshness | Current, delayed, stale, never observed | `lastupdate` against `step`: current within `step` plus an allowance; delayed up to `2 × step`; stale beyond the heartbeat; never observed when the file is absent or has no update since creation |
| Execution | Completed, probe error, unknown | Completed rounds have a numeric `loss`. Milestone 1 records what each probe writes on failure |
| Observed condition | Within policy, degraded, complete loss | Complete loss is `loss = pings` with `median` unknown; a stopped collector has every data source unknown after the heartbeat |
| Administrative | Enabled, disabled, archived | Plugin records |
| Maintenance | In maintenance, not in maintenance | Maint's `is_device_in_maintenance` hook for the device |
| Configuration (phase 2) | Applied, pending, drifted, failed | Revision records and include file hash |

Each cached observation carries observation time, ingestion time, expected interval, location, and configuration revision. A recent page refresh never counts as a recent measurement.

Freshness for each location comes from that location's own `target~slave.rrd`, so a slave that is behind shows as stale even when the master is current.

## Graphs

Smoke graphs are Cacti graphs. Graph trees, zoom, time spans, export, graph permissions, and `graph_buttons` integrations work as they do for any other Cacti graph. The plugin adds a `graph_buttons` link from a device's other graphs to its smoke graphs.

Calculation rules for the templates:

- Preserve SmokePing's median and distribution semantics.
- Use CDEFs and stacked areas that reproduce SmokePing's smoke bands from the sorted `pingN` values.
- Show missing data as missing.
- Label any percentile precisely. A percentile of interval medians is not a percentile of all packets.
- Call RTT spread "variation". The `pingN` values are sorted, so they cannot support a packet-order metric such as RFC 3550 jitter.

**Fidelity check.** Confirmed 2026-11-13 ([results](research/graph-fidelity.md)): the template's series equal SmokePing's on 2.7.3, 2.8.2, and 2.9.0, with the limits listed there (explicit upper limit, graph item order, 2.7.3 loss levels). The method: for each SmokePing version in the matrix, a fixture RRD with known values is graphed by SmokePing's CGI and by the Cacti template. The `rrdtool xport` output of each definition must match for median, loss, and every smoke band. Pixel comparison is not used, because fonts and rrdtool versions differ.

**Fallback.** If milestone 1 shows Cacti graph templates cannot reproduce the smoke bands exactly, the plugin adds its own renderer for smoke graphs only. That renderer accepts a target ID, location, and time range; never accepts paths, rrdtool expressions, or URLs; checks permissions on every request; and caches images outside the web root. This is a contingency, not the plan.

## Alerting and maintenance

The "SmokePing Summary" data source returns three values from the reader's cache:

| Value | Meaning | Missing or stale data |
|---|---|---|
| `median` | Median RTT of the latest completed round, in milliseconds | `U` |
| `loss_pct` | `loss / pings × 100` for the latest completed round | `U` |
| `age` | Seconds since the location's `lastupdate` | Always numeric, so Thold can alert on staleness |

Thold thresholds and Maint suppression work on these data sources with no change to either plugin.

Stated limits:

- **Sampling alignment.** Cacti polls on its own interval. If it is shorter than SmokePing's `step`, the same round is reported more than once; if longer, rounds are skipped. Documentation tells operators to set Thold durations to at least one `step`.
- **Late data.** Thold evaluates values when they are polled. Slave data that arrives later for a past window never re-triggers or clears an alert. The `age` value makes the delay visible while it happens.
- **Summary only.** Alerts see median, loss, and age, not the full distribution. The smoke graph is the investigation view.
- **Overlap with SmokePing alerts.** Operators can keep SmokePing's `alerts` section. The health page lists targets with both a SmokePing alert and a Thold threshold, so duplicate notifications are visible. The plugin does not disable either.

Alternatives considered:

| Option | Why not chosen |
|---|---|
| Plugin-owned incident engine | Duplicates Thold and Maint, adds a third notification path, and is the largest maintenance burden in revision 1 |
| Thold reading SmokePing RRDs directly | Thold evaluates polled values through `poller_output`; reading foreign RRD layouts would be a Thold redesign |
| Writing SmokePing timestamps into Cacti RRDs | Bypasses Cacti's poller and conflicts with boost |
| SmokePing's InfluxDB output | Nothing in Cacti consumes it |

**Maintenance.** Device maintenance works through Maint type 1 with no change. Target-level maintenance would add a Maint type, following the WebSeer and servcheck precedent, and reasons, tickets, owners, and timezones would be Maint schema changes. Both are proposed on the Maint repository first and are research for this plugin. Measurements continue during maintenance; only notifications are suppressed.

## Managed configuration (phase 2)

Managed mode writes only the Cacti-owned include file. Hand-maintained targets are never modified. Adopting an observed target creates a new managed target with a new RRD; the observed one stays read-only for history, and the import screen shows that before the operator confirms.

**Probe profiles** hold probe module, `step`, `pings`, timeout, source address, and module parameters from an allowlist per module. Phase 2 ships ICMP over IPv4 and IPv6 and TCP connect. Profiles never expose binary paths or arbitrary module options. HTTP, DNS, and other service checks stay available in observe mode, and the documentation points to servcheck for managed service checks. Device-originated probes such as Cisco RTT monitoring need SNMP on the device and are research.

**File permissions.**

- The include file and a sibling `requests/` directory are writable by a dedicated group that contains the Cacti poller user. Nothing else in SmokePing's configuration is writable by Cacti.
- An administrator installs the shipped systemd examples: a `.path` unit with `PathExists=` on `requests/reload`, and a `.service` unit that runs `systemctl reload smokeping.service`, writes `requests/result`, and removes the request. The service takes no input from Cacti beyond the file's existence.
- The [distribution matrix](research/distribution-matrix/README.md) records the daemon user, reload mechanism, and `--check` behavior per distribution. Every packaged unit defines `ExecReload=/bin/kill -HUP $MAINPID`. `smokeping --reload` fails on EL 9 and Fedora because their units write no PID file. Alpine uses OpenRC, so the path-unit design does not apply there, and its packaged `reload` calls a binary path that does not exist.
- `--check` runs as the packaged poller user (`www-data` on Debian and Ubuntu, `apache` on EL 9) on every matrix distribution. On EL 9 and Fedora the config is `0640 root:apache`, so a poller running as another user cannot read it.

**Workflow.**

1. Prepare a revision. Show affected targets, added packets per second, and added RRD updates per second.
2. Validate fields and compare the revision's base hash with the include file on disk, to detect external edits.
3. Write the candidate include file and a candidate main config to a private temporary directory, and run `smokeping --config=<candidate> --check`. Run the same check against the live config first: if the baseline fails, the failure is environmental (missing `piddir`, missing `sendmail`) and the revision is not marked invalid. Milestone 1 confirms how `@include` resolves relative paths, which decides how the candidate main config is built.
4. Replace the live include file atomically (write, fsync, rename) and create `requests/reload`.
5. Verify: `requests/result` reports success, and each new target receives its first update within `2 × step`.
6. On failure in step 4 or 5, restore the previous include file, request reload again, and record the failure on the revision.

Milestone 1 also records what the running daemon does when a reload encounters an invalid config, because rollback depends on whether it keeps the previous configuration.

One revision applies at a time per poller, enforced by `register_process_start()` for the writer process. Changing `step` or `pings` on a managed target produces a migration plan: create a new target, keep the old RRD read-only, and link both in the device's history. Every revision records author, diff, check output, apply result, and verification result.

## Remote and distributed operation (phase 3)

When SmokePing runs on a Cacti remote data collector, that collector runs the reader, inventory, script server input, and config writer, following the servcheck and webseer pattern:

- Plugin configuration tables (targets, profiles, revisions) replicate to remote collectors through the `replicate_out` hook.
- The reader on each poller handles only targets whose `poller_id` matches.
- The reader writes the observation cache to the collector's local database, where the script server input reads it. Summary values reach the main server through the normal poller output path, which already handles offline and recovery modes.
- The main server fetches inventory and health on demand through plugin actions in the `remote_agent` hook, called with `call_remote_data_collector()`.
- Applying a revision is a `remote_agent` action that takes only a revision ID. The collector reads the revision content from the main database, checks that it is approved and unapplied, and runs the phase 2 workflow. Repeating the call for the same revision is harmless.

`remote_agent` authorizes callers by matching their address to an enabled poller's hostname. That is Cacti's existing trust model for remote collectors, and it is address-based. The documentation requires https for remote collector communication and a restricted network path between pollers. Inventory and health actions are read-only; the apply action does nothing without an approved revision in the main database.

SmokePing slaves appear as locations of a target, each with its own smoke and summary data sources. A SmokePing location is not a Cacti data collector, and the UI keeps the terms separate. Slave-to-master traffic is SmokePing's own protocol; the documentation requires TLS for `--master-url`.

## Code structure

Plugin code is object-oriented wherever Cacti's plugin contract allows it. No maintained Cacti plugin ships a class layout to copy yet (plugin_thold is procedural on `develop`), so milestone 2 defines the layout.

**Where procedural code remains.** Cacti calls plugins by function name, so these entry points stay procedural. Each is a thin shim, parseable on PHP 8.0, that checks the PHP version, validates input, and hands off to a class:

- `setup.php` lifecycle functions: `plugin_smokeping_install()`, `plugin_smokeping_uninstall()`, `plugin_smokeping_version()`, `plugin_smokeping_check_config()`, `plugin_smokeping_upgrade()`.
- Hook callbacks: `config_arrays`, `config_settings`, `draw_navigation_text`, `poller_bottom`, `replicate_out`, `remote_agent`, `graph_buttons`, `api_device_save`, `device_remove`, `data_source_remove`, `device_action_array`, `device_action_prepare`, `device_action_execute`.
- Page scripts such as `smokeping.php`, which include `include/auth.php`, read request variables with `get_filter_request_var()`, and call a page class.
- The script server input function and the `poller_smokeping.php` CLI entry point.

**Layout.**

- Classes live in `lib/*.php` under the `Cacti\Smokeping` namespace, one class per file, loaded by a small `spl_autoload_register()` loader registered only after the PHP version check. No Composer runtime dependency is added. Files stay at depth 2 or less because the gettext build and the POT check scan only `find . -maxdepth 2`.
- Settings use the `config_settings` hook. Logging uses `cacti_log()` with a `SMOKEPING` tag. Forms rely on Cacti's csrf-magic.
- Strings use the `smokeping` text domain in classes and shims, with a `locales/` directory and POT file.
- Realms: two, "SmokePing View" and "SmokePing Admin", each a list of page files registered with `api_plugin_register_realm()`.

**Planned classes.** Names are illustrative; milestone 2 settles the final set.

| Kind | Examples | Notes |
|---|---|---|
| Value objects | `Target`, `TargetPath`, `TargetId`, `Observation`, `Revision`, `ProbeProfile` | `readonly` classes built by named constructors such as `Target::fromRow()` |
| Enums | `Freshness`, `ExecutionState`, `Condition`, `ConfigState`, `TargetMode` | String-backed; stored as `varchar` values, never as list positions, so reordering cannot corrupt stored data |
| Repositories | `TargetRepository`, `ObservationRepository`, `RevisionRepository` | The only classes that issue SQL, always through `db_*_prepared()`; take an explicit connection so remote collector code chooses local or main |
| Readers | `RrdReader`, `InventoryReader` | `RrdReader` wraps Cacti's rrdtool pipe; both sit behind interfaces so tests use fixture RRDs and fixture inventory |
| Cacti object services | `DeviceProvisioner`, `DataSourceProvisioner` | Call `api_device_save()` and Cacti's data source and graph APIs; never write Cacti tables directly |
| Services | `FreshnessEvaluator`, `InventoryImporter`, `RevisionPlanner`, `ConfigWriter` | Return decisions as objects; a separate runner applies side effects, so decisions are testable without a database or filesystem |
| Infrastructure | `Clock` with system and fixed implementations; `IdGenerator` | Injected wherever time or randomness matters, so tests are deterministic |

**Rules.**

- Dependencies come in through constructors. No service locators and no static state, apart from Cacti's globals, which only shims touch.
- Interfaces exist only at real boundaries: rrdtool, SmokePing, the database, the clock, the filesystem, and remote collectors.
- `fromRow()` is the only place loose database values are normalized. Tests pin its behavior on empty strings, `NULL`, and malformed values before any logic depends on it, because Cacti stores many values as loose strings and normalizing them can silently change comparisons.
- PHPStan runs on the class tree with Cacti core on its scan path.

## Storage, jobs, upgrades, and recovery

Plugin tables hold inventory, mappings, profiles, revisions, audit records, observation cache, and health. They are created and upgraded with Cacti's tracked plugin table API, and the stored plugin version changes only after a migration succeeds. SmokePing stays the only writer of its RRD files.

The reader runs from `poller_bottom` through `exec_background()` as one `register_process_start()` process per poller, with a time budget. Runs are idempotent: an interrupted run leaves the cache consistent and the next run continues. All RRD reads in a run share one `rrdtool -` process, started with `proc_open()` because `rrd_init()` is write-only.

Disabling the plugin stops jobs and leaves SmokePing files untouched. Uninstalling offers an export, asks whether to drop plugin tables, and asks whether to delete plugin-created devices; it never removes RRD files or the managed include file. Backup documentation covers the Cacti database, SmokePing's config including the include file, and `datadir`. A restore test is part of release qualification for each phase.

## Performance

Capacity is stated in target-location series. Fixtures cover 100, 1,000, and 10,000 series on documented hardware with documented `step`, `pings`, and RRA settings.

Arithmetic at 10,000 series with 20 pings every 300 seconds:

- About 667 packets per second on average before bursts. Each slave adds the same from its location.
- About 33 SmokePing RRD updates per second, and about 33 `lastupdate` reads per second if the reader checks every series each step.
- About 230,000 `data_template_rrd` rows and about 250,000 graph item rows for the smoke objects (23 data sources and roughly 25 graph items per 20-ping series), plus 10,000 summary data sources polled by the script server.

Engineering targets, to be measured, not yet demonstrated:

- Plugin overview page under two seconds at 10,000 series.
- Cacti's device, data source, and graph pages stay within their normal response time at that row count.
- No page request runs a probe, reads more than a bounded number of RRD files, or waits for a remote collector.
- New observations visible within one `step` plus the reader interval.
- The reader stays within its time budget at 10,000 series, including on NFS.

The setup screen shows packet rate, RRD update rate, and Cacti object count before a bulk import or deployment.

## Security

| Boundary | Control |
|---|---|
| Browser to Cacti | Cacti session, csrf-magic, plugin realms, device and graph permissions, prepared queries, contextual escaping |
| Cacti to RRD files | Read-only mount at `<rra_path>/smokeping`; the health page reports an error if it is writable or reached through a symbolic link; smoke data source paths derived from inventory and confirmed with `realpath()` under the mount |
| Cacti to SmokePing config (phase 2) | Write access to the include file and `requests/` only; no `sudo`; reload by a fixed systemd unit that takes no input |
| Main server to remote collector (phase 3) | Cacti's `remote_agent` authorization and the configured https scheme; apply takes only a revision ID and reads content from the main database |
| Slaves to master | SmokePing's protocol; TLS documented as required |

Phase 2 needs a security review covering the config writer, include file generation, allowlisted probe parameters, and the systemd units. Phase 3 needs a review of the `remote_agent` actions. If the fallback renderer is built, it needs its own review.

## Testing

- **Fixtures.** RRDs with known values for normal operation, partial loss, complete loss, stopped collection, late slave data, and changed `step`; inventory JSON fixtures for each matrix version.
- **Matrix.** Containers for each SmokePing version against Cacti 1.2.31 and current `1.2.x`, on every PHP minor in the CI matrix. `setup.php` and shims are also linted under PHP 8.0.
- **Fidelity.** The `xport` comparison under [Graphs](#graphs).
- **File safety.** Delete a plugin-created device and its data sources, run Cacti's maintenance, and confirm the SmokePing RRD is unchanged. Repeat with `datadir` accidentally writable, to prove the purge-queue hook works.
- **Identity.** A reinstall followed by a new managed target never reuses an existing RRD path.
- **Permissions.** Every page, graph, and data source as an administrator, a scoped user, a user whose access was just revoked, and a user with no access to unmapped targets.
- **Remote.** Collector offline, recovery mode, replication after reconnect, and repeated apply calls for one revision.
- **Failure scenarios before each phase release:** DNS failure, stopped collector, clock skew between hosts, unreadable or full storage, interrupted reader, duplicate reader start, external edit during a revision, failed check, failed reload, maintenance start and expiry, interrupted upgrade.
- **Harness.** Use the harness maintained Cacti plugins use, and confirm the choice with maintainers before adding test or CI infrastructure.

## Milestones

| Milestone | Phase | Deliverables | Exit evidence |
|---|---|---|---|
| 1. Compatibility and fidelity | 0 | Version matrix with service users, file modes, reload commands, `--check` behavior, `@include` resolution, and invalid-reload behavior; confirmation of every fact table row; graph template fidelity spike; inventory script prototype; estimates | Template `xport` output matches SmokePing on every matrix version, or the fallback renderer is chosen with evidence |
| 2. Foundation | 1 | PHP guard, install and upgrade, realms, settings, inventory import, reader, health page | Fresh install and upgrade at 1.2.31; install refused cleanly on PHP 8.1; unauthorized access rejected |
| 3. Cacti objects | 1 | Template XML, device and data source provisioning, mapping hooks, purge-queue protection, `graph_buttons` | File safety and identity tests pass; Thold fires and recovers on fixture loss; Maint suppresses it |
| 4. Phase 1 release | 1 | Documentation, packaging, restore test | Pilot on a same-host and a shared-filesystem deployment |
| 5. Managed mode | 2 | Profiles, revisions, config writer, systemd examples, rollback | Invalid and conflicting revisions leave the running config untouched; security review complete |
| 6. Remote and distributed | 3 | `replicate_out`, `remote_agent` actions, slave locations | Offline, recovery, and late-data scenarios produce correct state; security review complete |

## Open decisions

| Decision | Options | Needed before | Decided by |
|---|---|---|---|
| Smoke rendering | Cacti graph templates (proposed); fallback renderer | Milestone 2 | Milestone 1 fidelity results |
| Plugin-created devices | Template-marked devices visible everywhere (proposed); a hidden device class | Milestone 3 | Core maintainers |
| Target-level maintenance | New Maint type with reason, ticket, owner, timezone fields; device-level only | After phase 1 | Maint maintainers |
| Remote smoke graphs | Shared filesystem only (proposed); rrdcached; summary graphs only | Phase 3 | Milestone 1 rrdcached test |
| Repository and name | `Cacti/plugin_smokeping`, or another name | Before any code is published | Cacti maintainers |
| Test harness | Whatever maintained plugins use. Milestone 2 uses Pest 3 from the locked shared plugin harness (`tests/tools/plugin-harness` in Cacti/cacti), as servcheck, webseer, monitor, and maint do, pending confirmation in #12 | Milestone 2 | Cacti maintainers |

## Risks

| Risk | Effect | Mitigation |
|---|---|---|
| Cacti graph templates cannot match SmokePing's smoke bands | Custom renderer and permission layer return | Fallback renderer limited to smoke graphs, with its own security review |
| Cacti data source deletion purges a SmokePing RRD | Measurement history lost | Read-only file permissions, purge-queue hook, health warning, release test |
| Object counts slow Cacti's own pages at 10,000 series | Poor experience for all Cacti users | Measure at milestone 3; document a supported series count |
| `--check` cannot run as the poller user when the poller runs as a user outside the config's group (EL 9 and Fedora config is `0640 root:apache`) | Managed mode validation fails | A second shipped systemd unit runs the check as the SmokePing user |
| Only root or the `smokeping` user can signal the daemon | Reload needs privilege | The systemd unit runs `systemctl reload`; the Cacti user never gains it |
| Address-based `remote_agent` authorization | A host sharing a poller's address can call actions | https, restricted poller network, apply requires an approved revision in the main database |
| Volunteer capacity | Later phases stall | Each phase ships on its own |

## Research

Not scheduled; revisited after phase 1:

- Coverage policies with exclusions, owners, and review dates. A stale inventory must never report full coverage.
- Service-quality reports with explicit timezone, resolution, aggregation, and treatment of unknown and maintenance periods. Precision beyond RRD retention needs a summary store written while detail exists.
- A scoped public API and CLI.
- An end-to-end canary checked by something other than the reader.
- Moving RRD files to preserve history across an observed-target rename.
- rrdcached for smoke graphs of remote SmokePing hosts.
- Device-originated probes.

## Upstream process

This repository holds the proposal and tracks its requirements as GitHub issues and milestones. Before code is proposed for the Cacti organization, open an issue there describing the problem, the phase plan, and the open decisions, and ask maintainers about the repository, name, plugin-created devices, and test harness. Maint changes are proposed as issues on the Maint repository first, then as separate pull requests there. No Thold change is needed.

## Changes from revision 2

- **Bridge removed.** Same-host and shared-filesystem reads need nothing extra. Managed writes use a group-writable include file, `--check`, and a systemd path unit. Remote SmokePing hosts use Cacti remote data collectors with `replicate_out` and `remote_agent`. This removes the socket, mutual TLS, credential file, lock file, and contract versioning.
- **Graphs moved to Cacti.** Smoke graphs are Cacti graph templates over absolute RRD paths, with file-permission and purge-queue safeguards, an RRDproxy exclusion, and a fallback renderer only if fidelity fails.
- **Plugin now runs on remote collectors** (`remote_collect:1`). The previous "main server only" section contradicted the distributed design.
- **Alerting folded into phase 1.** Thold needs no change. The incorrect Thold-agreement dependency was removed. `age` stays numeric when data is stale.
- **Devices required.** Every target maps to a Cacti device, created from a marked template on confirmation.
- **IDs never reused.** Managed section names are random, and apply refuses to reuse an existing RRD path.
- **PHP guard added.** `setup.php` and shims parse on PHP 8.0 and refuse to run below 8.2, so a 1.2.31 site on an older PHP is not broken by the plugin.
- **Inventory script.** A short Perl CLI using `Smokeping::get_parser()`, as `smokeinfo` does, instead of a service.
- **Managed probes narrowed** to ICMP and TCP because servcheck covers service checks.
- **Reader uses Cacti's rrdtool pipe.** Cacti object row counts added to the performance section.
- **Cacti idioms made explicit:** hook list, `config_settings`, `cacti_log()`, csrf-magic, two realms, text domain, template XML import, `varchar` enums, explicit database connections for remote code.
- **Phases renumbered:** observe with alerting, managed, remote. Milestones and tests updated to match.
