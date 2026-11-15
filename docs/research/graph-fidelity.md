# Smoke graph fidelity

Research for [#17](https://github.com/somethingwithproof/plugin_smokeping/issues/17), run 2026-11-13, and run again 2026-11-15 after [#81](https://github.com/somethingwithproof/plugin_smokeping/issues/81) added the standard deviation, am/s, and probe line to the legend, with the same results. Question: can a Cacti 1.2.31 graph template reproduce SmokePing's smoke bands, median line, and loss coloring exactly?

**Result.** Yes, for SmokePing 2.8.2 and 2.9.0 as shipped, and for 2.7.3 with that version's own loss levels. Every compared series is equal to the last bit. Three limits matter to operators: the graph needs an explicit upper limit, the template depends on graph item order, and SmokePing 2.7.3 draws different loss levels from 2.8 and later. They are listed under [Differences](#differences). The decision in [ADR 0003](../decisions/0003-render-smoke-with-cacti-graph-templates.md) stands and the fallback renderer ([#60](https://github.com/somethingwithproof/plugin_smokeping/issues/60)) is not needed.

## Method

`sh tests/fidelity/run.sh <work-dir>` runs everything in containers.

1. `make-fixtures.php` writes one RRD per ping count (10, 20, 30) with SmokePing's data sources and default archives. Each holds four 36-round segments at `step` 300: normal (no loss), partial loss (1 to pings/2 lost per round, stored as `U`), complete loss (`loss = pings`, `median` unknown), and missing data (no updates).
2. `build-templates.php` builds the templates in a Cacti 1.2.31 database with Cacti's own `sql_save()` and exports them with `get_item_xml()`. The shipped `templates/smokeping-templates.xml` is that export (less the stock data source profile, which import resolves by hash).
3. `cacti-args.php` creates a device with `api_device_save()` and a graph with `create_complete_graph_from_template()`, points the data source at `<path_rra>/smokeping/pN.rrd`, and records the `rrdtool graph` command that `rrdtool_function_graph()` sends. Cacti generated this command; nothing was written by hand.
4. `smokeping-args.pl` prints SmokePing's argument list for the same file and range. `Smokeping::smokecol()` and `Smokeping::RRDhelpers::get_stddev()` are called from the installed modules. The loss-color and median code is inline in `Smokeping.pm`, so the script ports it and prints a digest of each source block it mirrors.
5. `compare.pl`, run with each distribution's own rrdtool, compares the two definitions on five ranges per file (the whole file and each segment).

The graph maximum is the one input SmokePing computes from the data (1.5 times the largest median in the range, 1.5 when there is none). `ranges.pl` computes it the same way, and both definitions receive the same value.

| Check | What is compared |
|---|---|
| drawing | The ordered drawing items: kind, stacking, color |
| xport | `rrdtool xport` of every drawn series, cell by cell, as numbers |
| print | Median average, maximum, minimum, last, standard deviation, and am/s, and loss average, maximum, minimum, last, from `PRINT` of the same expressions. SmokePing prints the standard deviation as text rounded to 0.1 ms, so that value is compared as the same text |
| png | `--only-graph` renders of the two definitions, byte-identical |

## Results

| Distribution | SmokePing | rrdtool | Cacti template compared | Checks | Result |
|---|---|---|---|---|---|
| Debian 13 | 2.8.2+ds-1 | 1.7.2 | shipped | 60 | 60 pass |
| Fedora 45 | 2.9.0-16 | 1.11.0 | shipped | 60 | 60 pass |
| Ubuntu 22.04 | 2.7.3-3 | 1.7.2 | shipped | 60 | 22 pass, 38 fail (loss levels, below) |
| Ubuntu 22.04 | 2.7.3-3 | 1.7.2 | variant with 2.7 loss levels | 60 | 60 pass |

Each distribution run is 3 ping counts times 5 ranges times 4 checks. The xport check compares 27, 37, or 47 series for 10, 20, or 30 pings over 36 or 144 rows each, up to 6,768 cells per run, with a maximum relative difference of 0 on every passing run. The digests of the `smokecol`, `defs`, and `median` blocks are equal on 2.8.2 and 2.9.0; on 2.7.3 `smokecol` and `defs` are equal and `median` and the loss table differ (below).

A mutation check confirms the comparison can fail: changing one color or one factor in a loss-band CDEF is reported by `drawing`, `xport`, and `png`.

## Differences

1. **Upper limit.** SmokePing sizes each graph from the data (`findmax` in `Smokeping.pm`). The `cp` series clip pings at that maximum, so the smoke bands depend on it. A Cacti graph has a fixed upper limit, which the CDEFs read as `CURRENT_GRAPH_MAXIMUM_VALUE`. The template ships 0.05 s and lets each graph override it; the plugin sets each graph's limit from its file when it creates it and again about once a day, by SmokePing's rule over the last day ([provisioning](../provisioning.md#graph-scale)). When the limit is empty, Cacti substitutes 0 and the bands vanish (`lib/rrd.php`). Series are equal when both sides use the same limit; Cacti does not pick one from the data.
2. **Graph item order.** A CDEF can read only its own item's data source, but a smoke band subtracts two pings. Cacti names each DEF by the order in which items first meet a data source, so the band CDEFs name those DEFs by letter. Reordering, removing, or inserting items before the smoke items breaks the bands. The comparison fails if it happens. Operators should not edit the smoke graph items.
3. **Band thickness.** The loss overlay's width is the limit divided by the graph height, and the CDEFs hold the template's height (200). Another height changes the overlay's thickness, not any series.
4. **Loss levels on 2.7.3.** Version 2.7.3 draws seven fixed loss levels (0, 1, 2, 3, 4, pings/2, pings-1) with other colors and no complete-loss level. Version 2.8 replaced them with percentage-based levels and added the `pings/pings` level. The shipped template follows 2.8 and 2.9. The smoke bands, median, and loss series on 2.7.3 are equal to the template's; only the loss overlay differs. A test-only variant with the 2.7 table matches 2.7.3 exactly, so a 2.7 template is possible. Ubuntu 22.04 is the only in-scope distribution that ships 2.7.3, and its standard support ends 2027-06-01.
5. **Legend.** SmokePing computes the standard deviation in Perl, from `RRDs::fetch` of the median over the range, and divides the average by it for am/s. The template computes both in rrdtool: a `STDEV` VDEF over the median in milliseconds, and a CDEF that divides the average VDEF by it. Cacti names a VDEF after its item's position, so the am/s CDEF depends on item order too (item 2). On every compared range with data the standard deviation prints the same 0.1 ms text and am/s is equal to 1e-9. On a range with no median, rrdtool's `STDEV` is NaN and the template prints `nan ms`, where SmokePing prints `0.0 ms`. The probe line is per target; the plugin writes it on each graph from SmokePing's probe description (`lib/ProbeDescription.php`, a port of each probe's `ProbeDesc()` that the parser oracle compares with SmokePing's own). For FTPtransfer, whose description is the size of a file on the SmokePing host, the line names the probe. The template has no uptime background (only for dynamic hosts) and no `loss_background` or `nodata_color` options. These are optional or configuration-dependent in SmokePing and draw nothing in the default configuration.
6. **Not tested.** Only the full-resolution archive was compared (ranges up to 12 hours). Longer ranges read consolidated archives through the same `AVERAGE` DEFs and the same expressions, but that path was not exercised. The standard deviation may differ there: `RRDs::fetch` picks an archive by the range alone, while the graph's data follows its width.

## Template facts found on the way

- `cdef_items.value` holds 150 characters, so long expressions are stored as several custom-string items, which Cacti joins with commas.
- Cacti 1.2.31 keeps availability on the device, not on the device template, so "availability None" is applied by the plugin when it creates a device ([PLAN](../PLAN.md#cacti-objects)).
