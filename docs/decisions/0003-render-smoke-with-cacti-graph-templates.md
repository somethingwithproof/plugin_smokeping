# 0003. Render smoke graphs with Cacti graph templates over SmokePing's RRD files

Status: Accepted, pending fidelity evidence from milestone 1 (2026-11-13)

## Context

A plugin-owned renderer would need its own graph endpoint, image cache, permission checks, zoom, and export. In Cacti 1.2.31, `get_data_source_path()` uses an absolute `data_source_path` unchanged, and data templates allow the "None" input method, so a Cacti data source can point at a SmokePing RRD that Cacti never polls or writes.

## Decision

Ship a "SmokePing Smoke (N pings)" data and graph template for each supported `pings` value, with input method None and an absolute path to the SmokePing RRD. Smoke bands are drawn with CDEFs and stacked areas from the sorted `pingN` data sources.

## Consequences

- Graph trees, graph permissions, zoom, export, aggregates, and `graph_buttons` work as for any Cacti graph.
- Every target needs a Cacti device.
- Cacti's purge logic deletes or archives RRD files when data sources are deleted. For absolute paths it currently misses only by accident, so SmokePing's `datadir` must be read-only to Cacti, and the plugin also removes queued purges under `datadir`.
- With `storage_location` set to an RRDproxy, SmokePing paths do not exist on the proxy, so only summary data sources are created.
- One template per `pings` value; about 23 data sources and 25 graph items per 20-ping series.
- Remote SmokePing hosts need the RRD files readable on the main server for smoke graphs.

## Fallback

If milestone 1 shows the templates cannot reproduce SmokePing's smoke bands under an `rrdtool xport` comparison, a plugin renderer is built for smoke graphs only, with its own security review. This record is then superseded.

## Alternatives rejected

- **Plugin renderer by default.** Duplicates Cacti's graph features and permission model.
- **Linking to SmokePing's CGI.** Leaves access control separate, which is one of the problems the plugin exists to solve.
