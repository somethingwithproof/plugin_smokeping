# 0005. Use random, never-reused section names for managed targets

Status: Accepted (2026-11-13)

## Context

SmokePing stores each target's RRD at the path given by its position in the config tree. Section names must match `[-_0-9a-zA-Z.]+`. If a managed target's section name came from an auto-increment column, a plugin reinstall or database restore could reissue a name whose RRD already exists, and the new target would silently append to the old target's history.

## Decision

Managed targets live in a Cacti-owned `+ cacti` section included with `@include`. Each target's section name is `t` followed by 12 random base36 characters, generated once. The human name goes in `menu` and `title`. The config writer refuses to create a managed target whose RRD path already exists.

## Consequences

- Renaming a managed target is a label change; the RRD never moves.
- A restored or reinstalled plugin cannot merge two targets' histories.
- SmokePing's own CGI shows managed targets in one flat section; Cacti trees provide grouping.

## Alternatives rejected

- **Auto-increment IDs.** Reusable after reinstall or restore.
- **Human-readable section names.** Renames would move RRD files and lose history.
