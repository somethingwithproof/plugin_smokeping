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

`sh tests/run-perf-objects.sh <work-dir>` creates the targets and their devices, times the pages, provisions every target's summary and smoke objects through the plugin, times the pages again, then runs two poller cycles. Page times are p50/p95 over HTTP in milliseconds, 20 requests after one warm-up, logged in as the administrator, served by PHP's built-in server. The baseline is the same devices before the plugin's objects exist.

| Series | Device list | Data source list | Graph list | Plugin overview | Provisioning | Poller cycle (cmd.php, steady) |
|---|---|---|---|---|---|---|
| 100, baseline | 37/38 | 24/34 | 34/35 | 28/38 | | |
| 100, provisioned | 29/39 | 71/83 | 47/48 | 38/39 | 33 s (0.33 s per series) | 1.3 s |
| 1,000, baseline | 33/41 | 26/35 | 26/35 | 55/66 | | |
| 1,000, provisioned | 31/32 | 53/76 | 54/55 | 54/65 | 348 s (0.35 s per series) | 6.7 s |
| 10,000, baseline | 50/52 | 28/37 | 27/32 | 288/306 | | |
| 10,000, provisioned | 65/75 | 76/78 | 104/107 | 323/327 | 6,141 s (0.61 s per series) | 59.3 s |

At 10,000 series the database holds 260,000 `data_template_rrd` rows, 520,000 graph item rows and 10,000 poller items for the plugin. Creating the 10,000 devices took 96 s. Provisioning slows as the tables grow, so a bulk add of 10,000 series takes close to two hours. The first poller cycle, which creates the summary RRD files, took 53.7 s at 10,000; the steady cycle stays within a fifth of the 300 s interval with one cmd.php process.

### Against the requirements

- **REQ-PERF-001 (overview under two seconds at 10,000): met.** p95 0.33 s over HTTP.
- **REQ-PERF-002 (management pages within the baseline plus 20 percent): not met as written.** At 10,000 series the p95 rises by 44 percent on the device list, 111 percent on the data source list and 234 percent on the graph list; at 1,000 series by 117 percent on the data source list and 57 percent on the graph list. Every page stays under 110 ms p95. The baseline has no graphs or data sources at all, so any 20,000 Cacti graphs would raise it; the run does not compare against the same number of graphs from Cacti's stock templates.
- **REQ-PERF-003 (no probe, bounded RRD reads, no remote wait per request): met** (see the first section).
- **REQ-PERF-004 (load shown before a bulk add): met.** The estimate matches the rows the run counted.

## Supported series count

Not published yet. The measurements above support 10,000 series for the plugin's own work (reader, overview, poller cycle), but REQ-PERF-002 fails as written, so the count waits on a decision about that requirement: compare against the same number of graphs from Cacti's stock templates, or replace the relative bound with an absolute one.

## Reproducing

```sh
sh tests/run-perf.sh <work-dir>               # 100, 1,000, and 10,000 series
sh tests/run-perf.sh <work-dir> 1000          # one size
sh tests/run-perf-objects.sh <work-dir>       # Cacti's pages and the poller with every object
```

Each run seeds the targets with `tests/bin/perf-seed.php`, creates the RRD files with `tests/bin/make-fixture-rrds.php`, reads them with `poller_smokeping.php --force`, and renders the page with `tests/bin/perf-page.php`.
