# 0007. Expose SmokePing's datadir to Cacti through a read-only mount under `rra_path`

Status: Accepted (2026-11-13). Amends the path mechanism in [0003](0003-render-smoke-with-cacti-graph-templates.md); the rest of 0003 stands.

## Context

Record 0003 assumed `get_data_source_path()` uses an absolute `data_source_path` unchanged. That holds on Cacti 1.2.31. On the `1.2.x` branch since commit eef6bb07e (2026-09-24, GHSA-9qff-xccr-rrc5) and on `develop`, every expanded path goes through `data_source_path_within_rra()`. A path outside `rra_path`, a path with empty, `.`, or `..` segments, or a path through any symbolic link is replaced with the generated path and logged as a security event. Smoke data sources with absolute paths into SmokePing's `datadir` would graph empty RRDs from 1.2.32 on, with no error shown to the operator.

Tested on 2026-11-13 with the 1.2.x functions in a container: a read-only bind mount of a SmokePing `datadir` at `<rra_path>/smokeping` is accepted, the same files by absolute path are rejected, and a symlink at `<rra_path>/link` is rejected. Writes and unlinks through the read-only mount fail with `EROFS`, including as root.

## Decision

SmokePing's `datadir` is mounted read-only inside Cacti's `rra_path`, by default at `<rra_path>/smokeping`: a bind mount when SmokePing runs on the same host, or a read-only NFS mount when the directory is shared. Smoke data sources use `<path_rra>/smokeping/<Section>/<target>.rrd`. The plugin stores two settings: SmokePing's own `datadir`, used to map inventory paths, and the Cacti-side mount path, which must resolve inside `rra_path` without symbolic links. The layout is the same on 1.2.31 and 1.2.32, and no Cacti core change is needed.

## Consequences

- The read-only mount replaces file permissions as the primary protection for SmokePing's files. Cacti's purge reaches these paths, because they are now inside `rra_path`; `unlink` and archive `rename` fail, Cacti logs a warning, and the queue entry is dropped. The plugin's `device_remove` and `data_source_remove` hooks still remove queued entries under the mount, so the warnings do not appear.
- The health page checks that the mount path exists, lies inside `rra_path` with no symbolic link segment, and is mounted read-only (from `/proc/self/mountinfo`). A symlink or a writable mount is an error, not a warning.
- Cacti's RRD cleaner walks `rra_path` recursively, so SmokePing RRDs with no Cacti data source, including slave `target~slave.rrd` files, are listed as unused. Deleting them from that page fails against the read-only mount. The install guide says so.
- Installation needs root once, to add the mount (an `/etc/fstab` line or a systemd `.mount` unit). The install guide ships both.
- Containerized Cacti deployments need the mount inside the container's `rra_path`.

## Alternatives rejected

- **Absolute paths, as in 0003.** Broken on 1.2.x and `develop`.
- **A symlink under `rra_path`.** Rejected by the same check.
- **A core setting listing extra read-only RRD roots.** Cleaner for operators, but it needs a core change and its own security review, and the plugin's goal is to run on 1.2.31 unchanged.
- **The fallback renderer (#60).** Brings back a plugin permission layer and loses Cacti's graph features.
