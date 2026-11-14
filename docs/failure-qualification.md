# Failure qualification of observe mode

REQ-TEST-010 ([#46](https://github.com/somethingwithproof/plugin_smokeping/issues/46)) lists the failures observe mode must handle with a documented state and no data loss. Each scenario below is scripted and passes; how to run them is at the end.

## Scenarios

| Scenario | Script | Documented state, as checked |
|---|---|---|
| SmokePing's configuration unreadable (the inventory cannot run) | `tests/failure-e2e.sh` | Inventory status `error`; all targets stay present; nothing deleted |
| DNS failure | `tests/failure-e2e.sh` | SmokePing's probes fail and it writes complete loss. Freshness `current`, condition `complete_loss`, summary `median:U loss_pct:100`. The plugin has no name lookup in its code (checked by search), so it cannot fail on DNS itself |
| Stopped collector | `tests/failure-e2e.sh`; also `tests/alert-e2e.sh` (an `age` threshold fires when the collector stops and recovers when it resumes) | `median:U loss_pct:U`, `age` numeric and growing; a file never updated is also unknown. Never zero loss |
| Clock skew between hosts | `tests/failure-e2e.sh` | SmokePing 10 minutes ahead: reads `current`, with a negative age, so a stopped collector hides for as long as the skew. A reader clock 30 minutes ahead of SmokePing's: `stale`. Documented in [operations](operations.md#when-inputs-fail) |
| Unreadable storage | `tests/failure-e2e.sh` | A garbage file and a directory in place of a file: the reader carries on, the rest are cached, the bad ones are unknown. A file that goes bad after a good read keeps its last cached reading unchanged, to age out through `delayed` and `stale` |
| Full storage | `tests/failure-e2e.sh` | The cache write is refused: the run exits 0 and every cached row is exactly as before; the next cycle after recovery caches the new round |
| Interrupted reader | `tests/failure-e2e.sh` | Killed with SIGKILL mid-run: every cached row is a whole reading; the next run starts at once (Cacti detects the dead process) and completes all series |
| Duplicate reader start | `tests/failure-e2e.sh` | The second start exits 0 and logs a note; one run is recorded; one cache row per series |
| Maintenance start and expiry | `tests/alert-e2e.sh` (`maint-suppresses-notification`, `measurements-continue-in-maint`, `alert-after-maint`) | Notifications are suppressed in maintenance while the summary data source keeps being polled; the alert fires once maintenance ends if the threshold is still breached |
| Interrupted upgrade (the plugin's own) | `tests/Integration/LifecycleTest.php` ("finishes an upgrade that was interrupted after creating only some tables") | The recorded version changes only after every step succeeds; the next run completes the upgrade |
| Interrupted upgrade of managed configuration | not scripted | **Deferred to M5**: it needs managed mode, which writes SmokePing's configuration and does not exist in observe mode |

Each script also checks that SmokePing's files are not changed by the scenarios (`smokeping-files-untouched`), which is the "no data loss" half of the requirement on the plugin's side: the plugin only reads them.

## Running them

```sh
sh tests/run-failure-e2e.sh <work-dir>      # all scenarios except maintenance and the upgrade
sh tests/run-alert-e2e.sh <work-dir>        # stopped collector and maintenance, about three minutes
sh tests/integ.sh ...                       # see tests/Integration for the upgrade scenarios
```

The failure script needs a Docker network with MariaDB (`cpt` by default), the `spm2-e2e` image (`tests/docker/Dockerfile`), and a Cacti 1.2.31 tree, like the other runners; [testing](testing.md#the-end-to-end-runners) lists the variables that change them. It prints one `RESULT` line per check and exits non-zero on any failure.

## What these do not cover

The scenarios use fixture RRD files written the way SmokePing writes them, not a running SmokePing daemon. DNS failure is therefore tested as the loss SmokePing records for it, not by breaking a resolver. Full storage is simulated by refusing the cache write; a real full disk may fail other writes first. These are the limits of a container test, and the release checklist repeats the stopped-collector and DNS cases against a real SmokePing on the project's runner.
