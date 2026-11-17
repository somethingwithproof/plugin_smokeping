# Operating the SmokePing plugin

How to read what the plugin shows, what each state means and what evidence it comes from, what to check when something looks wrong, and how the plugin behaves when its inputs fail. Installation is in [install](install.md), alerting in [alerting](alerting.md), backup in [backup and restore](backup-restore.md).

See [Access roles](../README.md#access-roles) for the View, Operator and Admin
permissions and personal triage behavior.

## Pages

| Page | Realm | Shows |
|---|---|---|
| **SmokePing** (a top tab) | View | Latest state of the targets mapped to devices you may see, one row per target and location |
| **SmokePing Inventory** | Admin | Every target SmokePing's configuration lists, mapped or not, and open re-link proposals |
| **SmokePing Health** | Admin | The checks below, and a button to retry the template import |
| **SmokePing Configuration** | Admin | Export and import of the plugin's configuration ([format](configuration-format.md)) |
| **Import SmokePing Configuration** (under Import/Export) | Admin | Import of an existing SmokePing configuration into devices, graphs, and a graph tree ([how](provisioning.md#a-whole-smokeping-configuration)) |

Smoke graphs and summary graphs are ordinary Cacti graphs, so Cacti's graph and device permissions govern them.

## What each state means

Each row of the SmokePing page combines independent dimensions. They are never merged into one color, because a stopped collector and complete packet loss need different responses.

| Dimension | States | Evidence |
|---|---|---|
| Freshness | `current`, `delayed`, `stale`, `never` | The age of the RRD's last update (read with `rrdtool lastupdate`) against the target's `step`. Current: within one `step` plus the freshness allowance (default 60 s). Delayed: older, up to twice the `step`, which is SmokePing's heartbeat. Stale: beyond the heartbeat, when SmokePing's values have gone unknown. Never: the file is missing, or has had no update since it was created |
| Execution | `completed`, `probe_error`, `unknown` | A round completed when it wrote a numeric loss. Stale or never-observed data says nothing about the last round, so it is `unknown`. `probe_error` is never set: a probe that fails writes the same values as a target that does not answer ([research](research/probe-failures.md)) |
| Condition | `within_policy`, `degraded`, `complete_loss`, `unknown` | From the last completed round. Complete loss is `loss` equal to `pings` with no median. Any loss below that is degraded. A stopped collector is never shown as loss: it goes stale and the condition is `unknown` |
| Administrative | `enabled`, `disabled`, `archived` | The plugin's record of the target. A disabled or archived target is not read |
| Maintenance | in or not in maintenance | Maint's `is_device_in_maintenance` hook for the target's device, shown in the Maintenance column of the SmokePing targets page. Without Maint, or with it disabled, every device reads as not in maintenance |
| Measurement time | seconds since the last update | The RRD's own timestamp. A page refresh never counts as a measurement |

The freshness of each location comes from that location's own file (`target~slave.rrd` for a slave), so a slave that is behind shows as stale even while the master is current.

The loss column reads `lost/pings` of the last round, and the median is in milliseconds; `U` means unknown, which is not zero.

## Health checks

| Check | OK means | When it is not OK |
|---|---|---|
| `php_binary` | The PHP binary Cacti's settings name exists and is executable | The reader cannot start: fix Cacti's "PHP Binary Path" setting |
| `reader` | The last run finished within three poller intervals, inside its time budget | Warning: the reader is late or ran over its budget. With many series raise the budget or check the storage; see [performance](performance.md) |
| `inventory` | The last inventory parsed SmokePing's configuration | Error: the message is SmokePing's parser's, with the file and line. The previous inventory stays in use |
| `mount`, `read-only`, `writable` | The mount path is inside the RRA path, reached without a symbolic link, mounted read-only, and not writable | Error. See the [install guide](install.md#3-mount-the-data-directory-read-only-inside-cactis-rra-path) |
| `visibility` | The files the inventory lists are visible through the mount | Warning, naming how many are missing |
| `templates` | The SmokePing templates are imported | Error with the cause; fix it and use **Retry Template Import** |
| `device_pollers` | Every device with summary data sources is polled by the reader's poller | Error naming the devices: their summary values and alerts have no data until they are polled by that poller |
| `loss_colors` | Which loss colors the Loss Colors setting selects: those of the SmokePing the reader found, or the ones it names. After a change the main poller moves existing graphs over the next cycles, 200 per cycle; `upgrade-graphs` moves them at once. The row counts the graphs still on the other colors, and warns about those whose move failed | Unknown when the reader found no SmokePing version and the setting is Automatic: the graphs use 2.8 and later colors. Warning when the setting differs from the version found. See [loss colors](provisioning.md#loss-colors) |
| `dual_alerting` | No target has both a SmokePing `alerts` value and an enabled Thold threshold on its summary data source | Warning naming up to 10 targets: a problem that meets both alerts' conditions notifies twice. The check does not compare the conditions; a Thold `age` threshold, for example, can fire when no SmokePing loss alert does. Compare them before removing the target's SmokePing alert or disabling its threshold. OK when Thold is not installed |

## Typical situations

- **Everything is `stale`.** SmokePing stopped writing, or the mount is stale. Check `systemctl status smokeping`, then compare `rrdtool lastupdate` through the mount with the same file on the SmokePing host. A read-only NFS mount can lag by its attribute cache time.
- **One slave is `stale`, the master `current`.** The slave stopped delivering. The master applies a slave's late results when they arrive, so the graph backfills; Thold does not re-evaluate them ([alerting](alerting.md)).
- **A target says `never`.** The RRD file does not exist yet (SmokePing creates it on the first round after a reload) or the mount does not show it. Check the `visibility` finding.
- **A graph is empty after a Cacti upgrade to 1.2.32.** A data source path outside the RRA path is replaced by Cacti's generated path. Check that the mount is where the plugin says it is and that nothing is a symbolic link.
- **A renamed SmokePing section.** SmokePing starts a new file for a new path. The inventory proposes a re-link and changes nothing until an administrator confirms ([provisioning](provisioning.md#re-linking-a-renamed-target)).

## Topologies

1. **SmokePing on the Cacti poller's host.** The poller user reads the data directory through the bind mount. This is what the install guide describes.
2. **SmokePing's data directory shared with the Cacti host.** Mount it read-only over NFS at the RRA path on the host that runs the SmokePing poller. The health page compares each file's last update with the clock, so a lagging mount shows as `delayed` or `stale`. Smoke graphs render on the Cacti server that serves the web UI, so that server needs the share too; if only the poller has it, the summary data sources and alerts work but smoke graphs are empty.
3. **SmokePing on a Cacti remote data collector** is a later phase and is not supported in this release.

Only the poller named in **SmokePing Poller** reads SmokePing data. Devices with summary data sources must be polled by it.

## When inputs fail

These are the behaviors the release is qualified against ([failure qualification](failure-qualification.md)); none of them loses data.

| Failure | Documented behavior |
|---|---|
| SmokePing's configuration unreadable or broken | The inventory fails with the parser's message on the Health page; the previous inventory stays in use and nothing is deleted |
| A probe cannot run while SmokePing keeps going: its binary exits with an error, the source address is not on the host, fping rejects a parameter, or IPv6 is off for FPing6 | SmokePing writes complete loss for every target of the probe, the same values as targets that do not answer. Measurements show `complete_loss`. When every target of one probe shows complete loss at once, look in SmokePing's log for the probe's error |
| A probe failure SmokePing detects: a missing binary, a parameter out of range, no raw socket for fping | SmokePing exits, at startup or at the first round. Every target goes `delayed`, then `stale`, as when SmokePing stops |
| Hostnames in targets do not resolve (DNS failure) | SmokePing's probes fail and write loss; the plugin reads what SmokePing wrote. Measurements show `degraded` or `complete_loss`, not stale. The plugin itself does no name lookups |
| SmokePing or its collector stops | Freshness goes `delayed`, then `stale`; `median` and `loss_pct` go unknown, `age` keeps growing. Never zero loss |
| Clock skew between the Cacti and SmokePing hosts | Freshness compares the RRD's own update time with the Cacti host's clock, so skew shifts every age by the skew. A SmokePing host ahead of Cacti stamps rounds in the future, which read as `current`, and a collector that stops stays `current` until the skew has passed. A SmokePing host behind Cacti makes every series look older by the skew, and `stale` once it exceeds twice the `step`. Keep both hosts on NTP |
| An RRD file unreadable or corrupt | The reader logs a warning for that file and carries on with the rest. A series never read is unknown (`U`). A series read before keeps its last cached reading, which ages through `delayed` and `stale` to unknown, so a file that goes bad never turns into zero loss |
| Full storage on the Cacti host | The reader writes only its database cache. When a write is refused the run exits normally and every cached row stays as it was, whole; the next cycle after the storage recovers caches the new rounds. Cacti's own RRDs for summary data sources fail like any other data source |
| Reader killed in the middle of a run | Each cache row is written whole, so a killed run leaves rows that are complete readings. Cacti detects the dead process and the next run starts at once and completes every series |
| A second reader start | Cacti's process table lets one reader run per poller. The duplicate exits 0 at once and logs a note (at log verbosity Medium); the first run is not disturbed |
| Maintenance starts and ends | Maint suppresses notifications for the device; Thold keeps evaluating and the summary data source keeps being polled |
| Interrupted plugin upgrade | The recorded version changes only after every step succeeds; the next page load or poller cycle finishes the upgrade |

## Import and export

See [configuration format](configuration-format.md). The short form:

```sh
php plugins/smokeping/cli/smokeping.php export --file=/var/backups/smokeping-config.yaml
php plugins/smokeping/cli/smokeping.php import --file=/var/backups/smokeping-config.yaml
```

## Logs

The plugin logs to Cacti's log with the `SMOKEPING` tag. `grep SMOKEPING log/cacti.log` shows the reader's statistics (`SMOKEPING STATS`), inventory failures, template import failures, and every configuration import that was applied.
