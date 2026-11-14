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

## Re-linking a renamed target

SmokePing names a target's file after its place in the configuration tree, so renaming or moving a node starts a new, empty file. When an import sees a path disappear and a new path with the same host and probe appear, it records a proposal and changes nothing. **Review** on the inventory page opens a confirm screen. Re-link moves the smoke graph and the summary data source to the new target, moves the device mapping with them, and keeps the old file's history readable in an archived graph. **Dismiss Proposal** closes it without a change.

## Permissions

The plugin has two realms. **SmokePing View** lets a user open the SmokePing page, which lists only targets mapped to devices the user is allowed to see, and follow the link from a device's graphs. **SmokePing Admin** opens the inventory and health pages and the actions above. An administrator whose device permissions are limited sees unmapped targets and the targets of the devices they may see.

Graphs and data sources are ordinary Cacti objects, so Cacti's own graph and device permissions decide who sees them. Cacti's default permission method is permissive: a user is limited to some devices only when the device, graph, and graph template policies all deny. Permission changes apply on the user's next request.

## Deleting a device

Deleting a mapped device removes its mapping and the plugin's records of its data sources and leaves the target in the inventory, unmapped. SmokePing's files are not touched: see [ADR 0007](decisions/0007-read-only-mount-under-rra-path.md).
