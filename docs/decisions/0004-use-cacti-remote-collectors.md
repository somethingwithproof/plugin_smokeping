# 0004. Reach remote SmokePing hosts through Cacti remote data collectors, not a custom bridge

Status: Accepted (2026-11-13)

## Context

Revision 2 of the plan proposed a bridge service on the SmokePing host: a Unix socket locally, mutual TLS remotely, its own credentials and contract versioning. Cacti already has remote data collectors. servcheck and webseer run work on them through `remote_collect:1`, the `replicate_out` hook, and `poller_bottom` jobs filtered by `poller_id`. Core 1.2.31 provides `call_remote_data_collector()` and a `remote_agent` hook for plugin actions.

## Decision

SmokePing always runs on, or shares a filesystem with, a Cacti poller. That poller runs the reader, inventory, script server input, and config writer. Remote SmokePing hosts run a Cacti remote data collector. The main server calls plugin actions through `remote_agent`. Applying a revision takes only a revision ID; the collector reads the content from the main database. Managed reloads use a systemd path unit that takes no input from Cacti.

## Consequences

- No new daemon, socket, credential store, or protocol.
- A remote SmokePing host needs a full Cacti remote collector: PHP, a web server, and a local database.
- Remote CLI jobs use the collector's local database by default and must handle recovery mode.
- `remote_agent` authorizes callers by address, not cryptographically. Documentation requires https and a restricted network between pollers.

## Alternatives rejected

- **Custom bridge service.** The largest security surface in the earlier plan, duplicating what Cacti remote collectors provide.
- **Direct SSH from the main server.** Requires key management and a privileged remote command path.
