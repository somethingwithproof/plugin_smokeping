# Adding SmokePing targets to Cacti

Every SmokePing target the inventory finds is visible to administrators on **SmokePing Inventory** and invisible to everyone else until it is mapped to a Cacti device. Nothing is created without an administrator's confirmation. The plugin never writes SmokePing's files.

## One target

On SmokePing Inventory, an unmapped, present target has an **Add to Cacti** link. The confirm screen offers two choices:

- **Create a new device** from the "SmokePing Target" template. The device uses the target's host, availability checking is off (SmokePing already measures it), and it is assigned to the poller that runs the reader.
- **Map it to an existing device.** The dropdown lists the devices you may change.

Either way the target gets a summary data source and graph for alerting (see [alerting](alerting.md)) and, when SmokePing's data can be graphed, a smoke graph for every location. A target gets no smoke graph, and the page says why, when its `pings` is not 10, 20, or 30, when Cacti stores RRD files on an RRDproxy, or when its file is not visible through the read-only mount. Such a target keeps its summary data.

The summary data source is polled by the poller that runs the reader. When an existing device is polled by another poller, the plugin does not create the summary data source and names the poller to use. The health page lists devices that were moved away later.

## Many devices

On the device list, select devices and choose **Add SmokePing Targets**. The confirm screen lists, for each device, the unmapped targets whose SmokePing `host` equals the device's host name (compared without regard to case), and how many match in all. Continue adds them. Devices with no match are reported and left alone, and no device is created. A hundred devices in one request took about 24 seconds in a local container; a very long selection stops starting new devices shortly before the request limit, and running the action again finishes it, because devices already added are skipped.

## A whole SmokePing configuration

**Console > Import/Export > Import SmokePing Configuration** brings an existing SmokePing installation into Cacti in one step. It needs the SmokePing Admin realm and device permissions that cover every device; mirroring into a graph tree also needs the Trees realm.

Choose the input:

- **The configured file** (the default): the SmokePing Configuration File setting, read on the server with every file it includes. An include must stay in that file's directory or below it.
- **An uploaded file** of at most 4 MiB. It is read on its own, so `@include` is not allowed in it and is refused with its line number. Its targets are graphed from the RRD files the reader's poller sees, and the next inventory marks a target that poller's SmokePing does not have as absent.

Then the options:

| Option | Default | Meaning |
|---|---|---|
| Devices | One per SmokePing host | Targets that probe the same host share a device. One per target gives every target its own device, named by its title. |
| Device Template | SmokePing Target | The template of devices the import creates. |
| Poller | The reader's poller | Must be the poller that runs the reader; any other is refused, because summary data sources there get no data. |
| Site | None | The site of new devices. |
| Graph Tree | Do not mirror | An existing tree, or a new tree by name. Each SmokePing section becomes a branch, and each device a host entry under the branch of its targets. |

**Preview** reads the configuration and writes nothing. It shows the SmokePing sections with a check box each (check sections and **Update Preview** to import only those targets), the devices to create or the existing devices to use, the graphs to create, the targets that get summary data only and why (pings other than 10, 20, or 30, or an RRDproxy), the tree branches and entries, and the estimated load, as the bulk action shows it. A device is reused when one on the chosen poller has the target's host name (and, with one device per target, its title as description); when two match, those targets are skipped and listed, and you map them on SmokePing Inventory. **Import** applies exactly the previewed plan through Cacti's device, graph, and tree functions. If the configuration or Cacti changed since the preview, nothing is written and you preview again.

Running the import again creates nothing new: mapped targets keep their devices, existing graphs and tree entries are reused, and the result lists each target as already in Cacti. The import never writes SmokePing's files or the mount.

The same import from the command line, with the same options:

```sh
php plugins/smokeping/cli/smokeping.php import-smokeping --dry-run --tree-name=SmokePing
php plugins/smokeping/cli/smokeping.php import-smokeping --apply --confirm=PLAN --tree-name=SmokePing
```

`--file=PATH` reads another file on its own, as an upload. `--sections=Europe,Lab/Core`, `--device-per=target`, `--device-template=ID`, `--poller=ID`, `--site=ID`, and `--tree=ID` set the other options. The dry run prints the plan and its id; `--apply` needs that id, and it must still match.

## Graph scale

SmokePing sizes each graph from its data at every render. A Cacti graph has one upper limit for every time range, so the plugin applies the rule SmokePing uses for its detail graphs (`findmax` in `Smokeping.pm`) to the last day: 1.2 times the largest median of the last day in that graph's file. SmokePing's Navigator Graph uses 1.5 times instead; the plugin has no navigator. It sizes a smoke graph when it creates it, and again about once a day after that. A graph whose file has no median in the last day keeps its current limit; a new one keeps the template's limit of 0.05 s (50 ms).

The daily sizing is on by default and has no setting. After each read, the reader sizes the smoke graphs of its poller's targets that were last sized a day ago or more, the longest waiting first, at most 25 per poller cycle. It reads one RRD file per graph and runs no probe. It skips a cycle in which the read used up the reader budget. At one poller cycle every five minutes that covers 7,200 graphs a day; with more, each graph is sized a little less often than daily.

The plugin records the limit it set on each graph. A limit that differs from that record was set by hand, on the graph's page, and the plugin never changes it. To hand a graph back to the plugin, set its limit back to the one the plugin last set, which `size-graphs` prints for each graph with a hand-set limit.

To size every smoke graph now:

```sh
php plugins/smokeping/cli/smokeping.php size-graphs
```

It prints each graph's new limit, that it kept its limit, or that it kept the limit set by hand. It follows the same rule about hand-set limits as the daily sizing. Sizing needs Cacti's rrdtool path (Configuration > Settings > Paths); without it graphs keep their limits.

On upgrade from 1.0.0-rc.1, nothing records who set the existing limits, so the plugin takes each smoke graph's current limit as its own and sizes the graph at its next daily turn. Before then, `size-graphs` replaced hand-set limits too. A limit counts as hand-set only while it differs from the one the plugin recorded, and after the upgrade the recorded limit is the adopted one. To keep a hand-set limit, set it again once a daily sizing has replaced it. If that sizing finds no median, it keeps the adopted limit, so setting the same value again changes nothing and a later sizing can still replace it.

## Graph legend and template upgrades

The smoke graph's legend follows SmokePing's: the median's average, maximum, minimum, and last value, its standard deviation, and am/s (the average over the standard deviation), then the loss values and colors, and SmokePing's probe line, such as `probe: 20 ICMP Echo Pings (56 Bytes) every 300s`. The probe line comes from the target's probe, packet size, pings, and step, which the inventory reads from SmokePing's configuration. The main poller writes it on each graph, and writes it again when the target's probe changes or a template import resets it. A target stored before the inventory has read its probe shows the probe's name.

When an upgrade brings new graph templates, graphs made from the earlier ones keep their old items, which still render, until the plugin brings them to the new templates. The main poller does that for 200 graphs per cycle, keeping each graph's title and upper limit. To do every graph now:

```sh
php plugins/smokeping/cli/smokeping.php upgrade-graphs
```

The upgrade sets each graph's items as the template has them, so changes made by hand to a plugin graph's items are replaced. A graph that cannot be upgraded keeps its old items, and the Cacti log names it.

## Re-linking a renamed target

SmokePing names a target's file after its place in the configuration tree, so renaming or moving a node starts a new, empty file. When an import sees a path disappear and a new path with the same host and probe appear, it records a proposal and changes nothing. **Review** on the inventory page opens a confirm screen. Re-link moves the smoke graph and the summary data source to the new target, moves the device mapping with them, and keeps the old file's history readable in an archived graph. **Dismiss Proposal** closes it without a change.

## Permissions

The plugin has two realms. **SmokePing View** lets a user open the SmokePing page, which lists only targets mapped to devices the user is allowed to see, and follow the link from a device's graphs. **SmokePing Admin** opens the inventory and health pages and the actions above. An administrator whose device permissions are limited sees unmapped targets and the targets of the devices they may see.

Graphs and data sources are ordinary Cacti objects, so Cacti's own graph and device permissions decide who sees them. Cacti's default permission method is permissive: a user is limited to some devices only when the device, graph, and graph template policies all deny. Permission changes apply on the user's next request.

## Deleting a device

Deleting a mapped device removes its mapping and the plugin's records of its data sources and leaves the target in the inventory, unmapped. SmokePing's files are not touched: see [ADR 0007](decisions/0007-read-only-mount-under-rra-path.md).
