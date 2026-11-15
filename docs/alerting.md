# Alerting on SmokePing data with Thold and Maint

The plugin does not send alerts. It gives Cacti a polled "SmokePing Summary" data source for each target location, and Thold and Maint work on it like on any other data source ([ADR 0002](decisions/0002-alert-through-cacti-data-sources.md)). Neither plugin needs a change. This page is the operator reference for what that data source reports and where the approach stops.

## What the data source reports

Each summary data source is polled by Cacti's poller through the script server. The script reads one row of the reader's cache and nothing else: it never opens an RRD file or contacts SmokePing while Cacti polls.

| Data source | Meaning | When it is `U` (unknown) |
|---|---|---|
| `median` | Median round trip time of the last completed round, in milliseconds | The round is older than SmokePing's heartbeat (twice the `step`), the RRD is missing or was never updated, or the round had no median (complete loss) |
| `loss_pct` | `loss / pings x 100` for the last completed round | The round is older than the heartbeat, the RRD is missing or was never updated, or the round wrote no loss value |
| `age` | Seconds since the RRD's last update | The RRD does not exist, or the reader has no cache row for it |

`median` and `loss_pct` are never `0` for missing data. A stopped collector therefore does not read as zero loss. `age` stays numeric while the RRD exists, including after the other two have gone to `U`, so staleness can alert. A file that was created but never updated reports the time since the plugin first saw the target.

A round from `step + freshness allowance` up to twice the `step` is "delayed": its values are still reported. Complete loss is `loss_pct` 100 with `median` `U`.

The summary data source must be polled by the Cacti poller that runs the reader (the "SmokePing Poller" setting), because the cache lives in that poller's database. The plugin refuses to create a summary data source on a device assigned to another poller and says which poller to use.

## Thresholds

Create Thold thresholds on `loss_pct`, `median`, and `age` from Thold templates or on each data source. Typical values:

| Alert on | Threshold | Notes |
|---|---|---|
| Packet loss | `loss_pct` high at 5 to 10 | Complete loss is 100 |
| Latency | `median` high at your limit in ms | `U` raises no latency alert |
| Collector stopped or slow | `age` high at 2 to 3 times the `step` | The only value that reports a stopped collector |

A threshold on `median` or `loss_pct` does not fire when the value is `U`. Use `age` for "SmokePing stopped measuring".

## Limits

These come from how Cacti and SmokePing sample, not from the plugin, and the plugin cannot remove them.

**Thold durations should be at least one `step`.** Cacti polls on its own interval and SmokePing measures on its own `step`. When the Cacti interval is shorter than the `step`, the same round is reported more than once. When it is longer, rounds are skipped. One breaching sample is then not one breaching round, so set the Thold trigger duration to at least one `step` (with the default 300 second step and 300 second poller interval, a trigger of 2 polls). A duration shorter than a `step` can alert on one round counted twice.

**Late slave data never re-triggers or clears an alert.** A SmokePing slave caches results it could not deliver and resends them, and the master applies them in timestamp order, so data for a past window can arrive late. Thold evaluates values when they are polled. A late round updates the RRD and the graph, but it does not change a value Thold already evaluated, so it will not raise an alert that was missed or clear one that is open. While a slave is behind, `age` for that location grows, which makes the delay visible.

**Alerts see the summary only.** Median, loss, and age describe the last round. The smoke graph is the view for investigating the distribution.

**SmokePing's own alerts are separate.** The `alerts` section of the SmokePing configuration keeps working. A target that alerts in both SmokePing and Thold produces two notifications; the plugin does not turn either off. The health page lists these targets under `dual_alerting`, from the `alerts` value the inventory reads, inherited as SmokePing inherits it.

**The Cacti RRD is a copy.** The summary values are stored in Cacti's own RRD as well as in SmokePing's, so a retention or consolidation difference between the two can show in graphs.

## Maintenance

A Maint schedule of type "Cacti device" on the target's device suppresses Thold notifications for the device. Thold keeps evaluating, and the plugin keeps filling the summary data source, so graphs and `age` have no gap. Notifications resume when the schedule ends and the threshold is still breached, after the trigger count again: Thold does not count breaches while the device is in maintenance.

Maint has no notion of a single target, only devices. A device that holds several targets is silenced for all of them. Target-level schedules would need a new Maint schedule type and are not part of this plugin.

## Verification

`tests/run-alert-e2e.sh` runs the whole path in a container with Cacti 1.2.31 and the `plugin_thold` and `plugin_maint` copies from a plugin workspace: a fixture collector appends rounds to a SmokePing-layout RRD, the reader caches them, `poller.php` polls the summary data source through the script server, and Thold and Maint act on it. It checks that a threshold on `loss_pct` fires after the trigger count and recovers, that a Maint device schedule suppresses the notification while the data source keeps being polled, that the alert fires once maintenance ends, and that a threshold on `age` fires when the fixture collector stops and recovers when it resumes.
