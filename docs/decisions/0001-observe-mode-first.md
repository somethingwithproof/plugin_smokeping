# 0001. Ship read-only observe mode first, in phases that each release

Status: Accepted (2026-11-13)

## Context

The first draft of the plan defined a single 1.0 release containing target management, an incident engine, reporting, an API and CLI, coverage policies, distributed monitoring, and a remote bridge. Nothing would reach users until every piece was finished, and much of the scope duplicated Thold, Maint, reportit, and servcheck.

## Decision

Release in phases that each ship on their own:

1. Observe mode: inventory, Cacti devices, smoke and summary graphs, Thold alerting, Maint suppression, health.
2. Managed mode: a Cacti-owned configuration namespace with ICMP and TCP probe profiles.
3. Remote and distributed operation through Cacti remote data collectors.

Coverage policies, service reports, a public API and CLI, canaries, and target-level maintenance are research.

## Consequences

- Phase 1 is useful to anyone already running SmokePing, without granting Cacti any write access.
- No phase depends on a later one, so stalled later phases do not strand earlier work.
- Operators who want Cacti to manage SmokePing wait for phase 2.

## Alternatives rejected

- **Single full-scope release.** Delays all value until the end and carries the largest maintenance load.
- **Managed mode first.** Requires the write path and its security review before any read-only value ships.
