# Performance

Capacity is stated in series: one target at one measurement location (the master, or a slave). This page says what the plugin costs at 100, 1,000, and 10,000 series, what it was measured against, and which numbers are still to come. Requirement IDs are from [#42](https://github.com/somethingwithproof/plugin_smokeping/issues/42).

## What the plugin does per request and per cycle

- **No page request runs a probe, reads an RRD file, or waits for a remote collector (REQ-PERF-003).** The overview page reads the reader's cache rows from the database. `tests/bin/perf-page.php` renders it with the mount path pointing at a directory that does not exist, and it renders the same. Graphs are Cacti graphs and read their files the way every Cacti graph does.
- **One reader process per poller reads every series once per cycle**, through one `rrdtool` pipe, with a time budget (default 120 s). A run that hits the budget stops and the next one resumes with the series that waited longest. Reading one file is a `lastupdate` call, which reads the file's header, so it does not depend on the size of the RRD.
- **The summary data sources read one cache row each** and never touch an RRD file.
- **The confirm screen of a bulk add shows the load before anything is created (REQ-PERF-004)**: for the matching targets, the series count, the probe packets per second and RRD updates per second SmokePing will carry, and the number of Cacti data source rows and graph item rows. The arithmetic is in `lib/LoadEstimate.php`.

## Arithmetic

At 10,000 series with 20 pings every 300 seconds: about 667 packets per second on average, about 33 SmokePing RRD updates per second, and about 33 `lastupdate` reads per second if the reader checks every series each step. The smoke objects are about 230,000 `data_template_rrd` rows (23 per series) and about 250,000 graph item rows (25 per series), plus 10,000 summary data sources. These are the numbers the confirm screen computes; the unit test pins them.

## Local smoke measurements

Measured on one laptop, in Docker containers, with the plugin's own scripts (`sh tests/run-perf.sh <work-dir>`): **a local smoke measurement of this code, not a release number.** Machine: Apple silicon, aarch64, 14 CPUs and 15.7 GiB available to Docker, MariaDB 10.11 and PHP 8.2 in containers on the same host, RRD files on a Docker bind mount. Series: 20 pings, 300 s step, fixture RRD files of 193 KB each (SmokePing's own files are several megabytes; `lastupdate` reads the header, so the size does not change the read).

| Series | Reader, first full pass | Reader, unchanged files | Series covered in one default (120 s) cycle | Overview page render (CLI, database only) | Page peak memory | Page HTML |
|---|---|---|---|---|---|---|
| 100 | 0.2 s | under 0.1 s | 100 of 100 | 0.002 s | 8 MB | 0.02 MB |
| 1,000 | 1.2 s | 1.1 s | 1,000 of 1,000 | 0.012 s | 12 MB | 0.17 MB |
| 10,000 | 12.3 s | 11.6 s | 10,000 of 10,000 | 0.119 s | 58 MB | 1.68 MB |

What the table shows: the reader costs about 1.2 ms per series here and grows linearly, so 10,000 series use about a tenth of the default budget on a local disk. The overview page lists every mapped series on one page (about 10,000 rows, 1.7 MB of HTML at 10,000), and its database and PHP time is small. These numbers leave out the browser, the web server, Cacti's own page frame, NFS, and a SmokePing that is writing the files at the same time.

Not measured here: Cacti's device, data source, and graph management pages at 230,000 data source rows (REQ-PERF-002), which needs the objects provisioned; a read-only NFS mount; and a real SmokePing daemon writing 33 files per second while the reader runs.

## Release measurements (to be filled in from the project runner)

The committed release numbers come from the project's DigitalOcean runner, on one droplet, with the same scripts. This table is a placeholder: nothing in it is a measurement.

| Series | Droplet | Reader, first full pass | Reader, unchanged files | Overview page over HTTP (p95) | Device list, data source list, graph list vs. baseline | Poller cycle | Notes |
|---|---|---|---|---|---|---|---|
| 100 | to be measured | | | | | | |
| 1,000 | to be measured | | | | | | |
| 10,000 | to be measured | | | | | | |

## Supported series count

Not published yet. [#42](https://github.com/somethingwithproof/plugin_smokeping/issues/42) asks for a supported count backed by measurements on documented hardware; it is set from the table above once the droplet numbers exist. Until then the plugin's own cost (above) is not the limit; the open question is Cacti's management pages and the poller at 230,000 data source rows, which belongs to Cacti, not this plugin.

## Reproducing

```sh
sh tests/run-perf.sh <work-dir>               # 100, 1,000, and 10,000 series
sh tests/run-perf.sh <work-dir> 1000          # one size
```

Each run seeds the targets with `tests/bin/perf-seed.php`, creates the RRD files with `tests/bin/make-fixture-rrds.php`, reads them with `poller_smokeping.php --force`, and renders the page with `tests/bin/perf-page.php`.
