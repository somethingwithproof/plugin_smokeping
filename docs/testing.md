# Testing

| Suite | Where it runs | What it needs |
|---|---|---|
| Unit and integration tests (Pest), PHPStan, PHP 8.0 entry-point lint, translation template check | CI, every pull request | Cacti checked out with the plugin under `plugins/`, MariaDB |
| `tests/oracle/run.sh`: the plugin's reader of SmokePing's configuration against SmokePing's own parser (2.7.3, 2.8.2, 2.9.0) | CI | Docker |
| The end-to-end runners, `tests/run-*-e2e.sh`: objects, permissions, alert, config, restore, release, failure, import, reader | CI, on Cacti `release/1.2.31` and `1.2.x`; and locally | Docker, MariaDB, the test image |
| `tests/run-smokeping-e2e.sh`: the plugin against SmokePing 2.7.3, 2.8.2 and 2.9.0 running in containers, each measuring a target that answers and one that cannot | CI, on Cacti `release/1.2.31` and `1.2.x`; and locally | As above, plus network access to build the SmokePing images |
| `tests/run-fidelity-e2e.sh` (`tests/fidelity/run.sh`): the graph templates against SmokePing's own graph definition in each package | CI, on Cacti `release/1.2.31`; and locally | As above |
| `tests/docs-commands.sh`: the install guide's commands on a real distribution | CI, on Debian 13 and AlmaLinux 9; and locally | A privileged container |
| `tests/release-tools-test.sh`, `tests/archive-policy-test.sh`: the release gate and the archive policy | CI; and locally | Git, a shell |
| `tests/lint-workflows.sh`: actionlint and zizmor on the workflows | CI; and locally | Docker |
| `tests/live/run-live-e2e.sh`: the plugin against a real SmokePing network | The release host only | Root, a running SmokePing master with slaves, Docker |

CI ends in one job, `ci-ok`, which needs all of the above except the live suite. Branch protection names `ci-ok` alone.

## The end-to-end runners

Each runner starts containers from the test image, so they all need Docker, a MariaDB reachable from a Docker network, and a Cacti tree. They print one `RESULT` line per check, save each container's whole output to `log-*.txt` in the work directory, and exit non-zero on any `RESULT FAIL` or when no result appears.

```sh
docker build -t spm2-e2e tests/docker          # the test image: PHP 8.2, Cacti's extensions, rrdtool, SmokePing's package
sh tests/run-config-e2e.sh <work-dir>          # or any other tests/run-*-e2e.sh
```

The reader runner covers the fixture RRDs of every measurement state: normal, partial loss, complete loss, stopped collection, late slave data, a file that was never written, and a changed `step`. SmokePing will not start while a file's step differs from its configuration, so after a step change the file stops being written; the reader judges it on the configured step and reports it stale.

The defaults describe the maintainer's machine: the network `cpt`, the MariaDB container `cpt-db`, the image `spm2-e2e`, the credentials in `../plugin-test-ws/docker/db.env`, and a Cacti 1.2.31 tree at `../plugin-test-ws/cores/1.2.31`. Everything is an environment variable, and CI sets them for its MariaDB service container:

| Variable | Default | Meaning |
|---|---|---|
| `E2E_IMAGE` | `spm2-e2e` | The test image |
| `E2E_NETWORK` | `cpt` | The Docker network the containers join |
| `E2E_DB_HOST` | `cpt-db` | MariaDB's host name on that network |
| `E2E_MARIADB_IMAGE` | `mariadb:10.11` | The image whose client administers the database |
| `CACTI_CORE` | `../plugin-test-ws/cores/1.2.31` | The Cacti tree. A tree without `include/config.php` gets `tests/Fixtures/e2e-config.php` for the run |
| `DB_ENV` | `../plugin-test-ws/docker/db.env` | An env file with `MARIADB_ROOT_PASSWORD`, `MARIADB_USER`, `MARIADB_PASSWORD`. When it does not exist, the same three variables are read from the environment |
| `SP_DB_NAME` | one per runner | The disposable database, which must start with `smokeping_` |
| `PLUGIN_SOURCE` | `../plugin-test-ws/cacti/plugins` | A directory with `thold/` and `maint/`, for the alert runner |

To run the suite the way CI does, without the local setup:

```sh
docker network create e2e
docker run -d --name e2e-db --network e2e -e MARIADB_ROOT_PASSWORD -e MARIADB_USER -e MARIADB_PASSWORD mariadb:11.8
E2E_NETWORK=e2e E2E_DB_HOST=e2e-db CACTI_CORE=/path/to/cacti sh tests/run-objects-e2e.sh <work-dir>
```

with the three credentials exported from your keychain or a secret manager, never typed on a command line.

## What stays out of CI

- **The live suite** needs a SmokePing that is running and writing its files, root for a bind mount, and a network that answers pings from several places. It runs on the release host at the release checklist ([RELEASING.md](RELEASING.md)).
- **The performance runs** of [performance.md](performance.md) measure one machine; their numbers are not portable to a shared runner.

## The live suite

`tests/live/run-live-e2e.sh <work-dir>` runs on a host that runs SmokePing 2.8.2 as its master (configuration in `/etc/smokeping/config` with `config.d/` split files, data in `/var/lib/smokeping`, step 60) and has Docker, MariaDB and the test image. The network it expects: five real hosts in London, Frankfurt (10 pings), Singapore, Bangalore (30 pings) and Sydney (also probed by Curl as `Web/SydneyHTTP`, on the same host), the unreachable `Faults/Unreachable` (192.0.2.1), and two slaves, `lon1` and `sgp1`, so every target has a master file and two `~slave` files. `Local/LocalMachine.rrd` belongs to no target, and `__cgi/` holds the slaves' caches.

For each Cacti tree (1.2.31, then the 1.2.32 copy) it:

1. makes a fresh database, bind-mounts the data directory read-only at the tree's `rra/smokeping` (`mount --bind`, then `mount -o remount,ro,bind`), and gives the container `/etc/smokeping` read-only;
2. installs the plugin and imports the real configuration (`cli/smokeping.php import-smokeping`, dry run first), with a graph tree that mirrors the sections;
3. runs real `poller.php` cycles, one a minute, for at least five minutes, with a Thold loss threshold on `Faults/Unreachable` and, as a control, on London;
4. asserts one device per host (the two Sydney targets share one), smoke and summary graphs for the master and both slaves with the 10-, 20- and 30-ping templates where they belong, the tree's branches, the plugin's cached and summarized values against `rrdtool lastupdate` on the same files (within one step, as SmokePing keeps writing), complete loss and a fired threshold on `Faults/Unreachable`, every graph rendering a PNG through `graph_image.php`, the health page (read-only mount, files visible, no error for `__cgi`), and that a second import creates nothing;
5. deletes a device and runs `poller_maintenance.php` on the live mount and checks that every RRD file is still there with the same inode and size;
6. swaps in a writable copy of the data directory and repeats the deletion, the RRD cleaner's selection of a target's file and of the orphan file, each followed by a real poller run, and checks that every file is byte-identical (a live file cannot be: SmokePing rewrites it every minute), then runs the same deletion with the plugin disabled as a control that the copy does lose files;
7. unmounts, drops its databases and removes the copy, and checks that SmokePing's configuration, its set of RRD files and its services are as it found them.

It never writes to `/var/lib/smokeping` or `/etc/smokeping`, and never touches the `smokeping` or `apache2` services. `LIVE_SECONDS` (330) and `LIVE_CYCLES` (5) set the shortest poller run; shorten them only to debug the suite.

The plugin does not walk SmokePing's data directory: the inventory comes from SmokePing's configuration, so `__cgi/`, `__sortercache/` and a file no target owns are never read, listed or reported. The suite asserts that, and records as `INFO` that the health page has no report of files that belong to no target.
