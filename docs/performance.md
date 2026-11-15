# Performance

Capacity is stated in series: one target at one measurement location (the master, or a slave). This page says what the plugin costs at 100, 1,000, and 10,000 series, what it was measured against, and which numbers are still to come. Requirement IDs are from [#42](https://github.com/somethingwithproof/plugin_smokeping/issues/42).

## What the plugin does per request and per cycle

- **No page request runs a probe, reads an RRD file, or waits for a remote collector (REQ-PERF-003).** The overview page reads the reader's cache rows from the database. `tests/bin/perf-page.php` renders it with the mount path pointing at a directory that does not exist, and it renders the same. Graphs are Cacti graphs and read their files the way every Cacti graph does.
- **One reader process per poller reads every series once per cycle**, through one `rrdtool` pipe, with a time budget (default 120 s). A run that hits the budget stops and the next one resumes with the series that waited longest. Reading one file is a `lastupdate` call, which reads the file's header, so it does not depend on the size of the RRD.
- **The summary data sources read one cache row each** and never touch an RRD file.
- **The confirm screen of a bulk add shows the load before anything is created (REQ-PERF-004)**: for the matching targets, the series count, the probe packets per second and RRD updates per second SmokePing will carry, and the number of Cacti data source rows and graph item rows. The arithmetic is in `lib/LoadEstimate.php`.

## Arithmetic

At 10,000 series with 20 pings every 300 seconds: about 667 packets per second on average, about 33 SmokePing RRD updates per second, and about 33 `lastupdate` reads per second if the reader checks every series each step. The Cacti objects are 260,000 `data_template_rrd` rows (23 for the smoke data source and 3 for the summary, per series) and 520,000 graph item rows (47 for the smoke graph and 5 for the summary graph, per series), with 10,000 summary data sources polled by the script server. The release run below counted exactly these rows. The confirm screen computes the same numbers, and `tests/Unit/LoadEstimateTest.php` checks them against the shipped templates.

## Local smoke measurements

Measured on one laptop, in Docker containers, with the plugin's own scripts (`sh tests/run-perf.sh <work-dir>`): **a local smoke measurement of this code, not a release number.** Machine: Apple silicon, aarch64, 14 CPUs and 15.7 GiB available to Docker, MariaDB 10.11 and PHP 8.2 in containers on the same host, RRD files on a Docker bind mount. Series: 20 pings, 300 s step, fixture RRD files of 193 KB each (SmokePing's own files are several megabytes; `lastupdate` reads the header, so the size does not change the read).

| Series | Reader, first full pass | Reader, unchanged files | Series covered in one default (120 s) cycle | Overview page render (CLI, database only) | Page peak memory | Page HTML |
|---|---|---|---|---|---|---|
| 100 | 0.2 s | under 0.1 s | 100 of 100 | 0.002 s | 8 MB | 0.02 MB |
| 1,000 | 1.2 s | 1.1 s | 1,000 of 1,000 | 0.012 s | 12 MB | 0.17 MB |
| 10,000 | 12.3 s | 11.6 s | 10,000 of 10,000 | 0.119 s | 58 MB | 1.68 MB |

What the table shows: the reader costs about 1.2 ms per series here and grows linearly, so 10,000 series use about a tenth of the default budget on a local disk. The overview page lists every mapped series on one page (about 10,000 rows, 1.7 MB of HTML at 10,000), and its database and PHP time is small. These numbers leave out the browser, the web server, Cacti's own page frame, NFS, and a SmokePing that is writing the files at the same time.

Not measured here: Cacti's management pages with the objects provisioned (see the release measurements), a read-only NFS mount, and a real SmokePing daemon writing 33 files per second while the reader runs.

## Release measurements

Measured on 2026-11-15 on one DigitalOcean droplet: `c-4` in sfo3, 4 dedicated vCPUs (Intel Xeon Platinum 8280), 8 GB, Ubuntu 24.04, with Cacti 1.2.31, PHP 8.5.12, RRDtool 1.7.2 and MariaDB 10.11.19 in containers on the same host. The series are 20 pings at a 300 s step, each target on its own device, with fixture RRD files on a read-only bind mount.

### Reader and overview page

`sh tests/run-perf.sh <work-dir>`:

| Series | Reader, first full pass | Reader, unchanged files | Series covered in one default (120 s) cycle | Overview page render (CLI, database only) | Page peak memory | Page HTML |
|---|---|---|---|---|---|---|
| 100 | 0.2 s | under 0.1 s | 100 of 100 | 0.004 s | 8 MB | 0.02 MB |
| 1,000 | 1.9 s | 2 s | 1,000 of 1,000 | 0.028 s | 12 MB | 0.17 MB |
| 10,000 | 20.6 s | 19.9 s | 10,000 of 10,000 | 0.244 s | 58 MB | 1.68 MB |

The reader costs about 2 ms per series on this host, so 10,000 series use about a sixth of the default budget.

### Cacti's pages and the poller with every object

`sh tests/run-perf-objects.sh <work-dir>` creates the targets and their devices, times the pages, provisions every target's summary and smoke objects through the plugin, times the pages again, then runs two poller cycles, each after the previous cycle's reader has finished. `PERF_OBJECTS=stock` gives each device two graphs from Cacti's own Local Linux Machine templates ("Unix - Load Average" and "Linux - Memory Usage") instead: the same number of graphs per series as the plugin's, the comparison for REQ-PERF-002. Page times are p50/p95 over HTTP in milliseconds, 20 requests after one warm-up, logged in as the administrator, served by PHP's built-in server. "Devices only" is the same devices before any graph exists.

| Series | Objects | Device list | Data source list | Graph list | Plugin overview |
|---|---|---|---|---|---|
| 100 | devices only | 28/31 | 25/35 | 35/37 | 38/40 |
| 100 | stock graphs | 28/39 | 48/56 | 33/48 | 29/39 |
| 100 | plugin objects | 28/39 | 51/53 | 33/34 | 28/29 |
| 1,000 | devices only | 30/42 | 25/26 | 26/36 | 59/64 |
| 1,000 | stock graphs | 32/33 | 50/51 | 39/40 | 65/66 |
| 1,000 | plugin objects | 42/44 | 54/77 | 39/40 | 58/68 |
| 10,000 | devices only | 50/57 | 28/37 | 28/30 | 301/319 |
| 10,000 | stock graphs | 66/69 | 50/75 | 107/111 | 331/338 |
| 10,000 | plugin objects | 65/68 | 54/78 | 105/119 | 318/340 |

| Series | Provisioning the plugin's objects | Poller cycle, first (creates the RRD files) | Poller cycle, steady | Reader |
|---|---|---|---|---|
| 100 | 31 s | 2.0 s | 1.6 s | 0.2 s |
| 1,000 | 324 s | 6.5 s | 5.1 s | 1.6 s |
| 10,000 | 3,284 s | 57.1 s | 43.5 s | 21.5 s |

At 10,000 series the plugin's objects are 260,000 `data_template_rrd` rows, 520,000 graph item rows and 10,000 poller items; the stock graphs are 50,000 and 240,000. The poller cycle is one cmd.php process polling the 10,000 summary data sources; the reader runs beside it, started by `poller_bottom`. Both stay well inside the 300 s interval. Provisioning is one administrator's bulk add: about 0.33 s per series, so close to an hour for 10,000. An earlier run of the same script on the same droplet took 6,141 s for 10,000 (0.61 s per series), so plan for one to two hours.

### Against the requirements

- **REQ-PERF-001 (overview under two seconds at 10,000): met.** p95 0.34 s over HTTP.
- **REQ-PERF-002 (management pages within the baseline plus 20 percent at 10,000): met** against the stock-graph baseline. At 10,000 series the plugin's p95 is 1 percent below the stock graphs on the device list, 4 percent above on the data source list and 7 percent above on the graph list. At 1,000 series the data source list's p95 is 51 percent above the stock graphs (77 against 51 ms) while the medians differ by 8 percent, in both runs: the slowest requests, not the typical one. Against the devices alone, with no graphs at all, every page is slower with any 20,000 graphs, stock or plugin.
- **REQ-PERF-003 (no probe, bounded RRD reads, no remote wait per request): met** (see the first section).
- **REQ-PERF-004 (load shown before a bulk add): met.** The estimate matches the rows the run counted.

## Supported series count

**10,000 series per Cacti installation**, at 20 pings and a 300 s step, on hardware like the release runner's: four dedicated vCPUs and 8 GB, with the database on the same host. At that count the reader uses a sixth of its default budget, a poller cycle a seventh of the interval, and Cacti's management pages stay within 7 percent of the same number of stock Cacti graphs. Beyond 10,000 nothing has been measured.

## Reproducing

```sh
sh tests/run-perf.sh <work-dir>               # 100, 1,000, and 10,000 series
sh tests/run-perf.sh <work-dir> 1000          # one size
sh tests/run-perf-objects.sh <work-dir>       # Cacti's pages and the poller with every object
```

Each run seeds the targets with `tests/bin/perf-seed.php`, creates the RRD files with `tests/bin/make-fixture-rrds.php`, reads them with `poller_smokeping.php --force`, and renders the page with `tests/bin/perf-page.php`.
