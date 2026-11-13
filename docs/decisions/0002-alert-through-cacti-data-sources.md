# 0002. Alert through polled Cacti data sources, not a plugin alert engine

Status: Accepted (2026-11-13)

## Context

Operators want alerts on SmokePing loss and latency through the same path as their other Cacti alerts, with Maint suppression. Thold receives values through Cacti's `poller_output` hook, so it only sees data sources the Cacti poller polls. It cannot read SmokePing's RRD files.

## Decision

Each target and location gets a "SmokePing Summary" data source using a script server input that returns `median`, `loss_pct`, and `age` from the reader's cache. Thold thresholds and Maint schedules apply to these data sources with no change to either plugin. `median` and `loss_pct` return `U` when data is missing or stale; `age` is always numeric so staleness itself can alert.

## Consequences

- One notification path for SNMP and SmokePing data; no Thold or Maint change.
- Alerts see summary values only, not the full distribution.
- Cacti's poll interval and SmokePing's `step` are independent, so a round can be reported twice or skipped. Thold durations should be at least one `step`.
- Late slave data for a past window never re-triggers or clears an alert.
- Summary values are stored twice: in SmokePing's RRD and in Cacti's.

## Alternatives rejected

- **Plugin-owned incident engine.** Duplicates Thold and Maint and adds a third notification path.
- **Thold reading SmokePing RRDs.** Would be a Thold redesign.
- **Writing SmokePing timestamps into Cacti RRDs.** Bypasses Cacti's poller and conflicts with boost.
- **SmokePing's InfluxDB output.** Nothing in Cacti consumes it.
