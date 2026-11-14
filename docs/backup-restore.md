# Backup and restore

A restored Cacti and SmokePing pair keeps working when three things are restored together: the Cacti database, SmokePing's configuration, and SmokePing's data directory. This page says what each holds, how to back it up, what to do after a restore, and how to restore the plugin's own configuration from its YAML export when the database is gone.

## What to back up

| What | Holds | Why the plugin needs it |
|---|---|---|
| The Cacti database | The plugin's tables (targets, mappings, the objects it created, the reader's cache, health), Cacti's devices, data sources, graphs, and the plugin's settings | Mappings, data source paths, and graph definitions live here |
| SmokePing's configuration: `/etc/smokeping/config` **and every file it includes** (`@include` lines, `config.d/*` on Debian and Ubuntu, a `secrets` file, `smokemail`, `basepage.html`) | The target tree | The inventory parses it. A target's identity is its path in this tree, and its RRD file is `datadir/<path>.rrd` |
| SmokePing's data directory (`datadir`) | The RRD files | Every graph and every summary value reads them; they are the history. They cannot be rebuilt |

The plugin's settings are in the database. The export described [below](#restore-from-the-yaml-export) is a second, text copy of the plugin's own configuration, not a replacement for any of the three.

## Taking the backups

Take the database dump and the file copies at about the same time, so that the targets in the database and the files on disk describe the same configuration.

```sh
# The Cacti database (use your own credentials file or option file, not a password on the command line)
mariadb-dump --single-transaction --defaults-extra-file=/root/.cacti-backup.cnf cacti > cacti.sql

# SmokePing's configuration, with its included files
tar -czf smokeping-config.tgz -C /etc/smokeping .

# SmokePing's data directory
tar -czf smokeping-datadir.tgz -C /var/lib/smokeping .

# The plugin's own configuration, as YAML (optional second copy)
php /var/www/html/cacti/plugins/smokeping/cli/smokeping.php export --file=/var/backups/smokeping-config.yaml
```

Use the `datadir` of your distribution (`/var/lib/smokeping/rrd` on EL and Fedora). SmokePing writes the RRD files continuously. A copy taken while it runs can hold a file mid-update; for a consistent copy take a filesystem or volume snapshot, or stop SmokePing for the copy (`systemctl stop smokeping`; the gap is a gap in measurements, not a loss of history). SmokePing's own data directory may contain other directories (`__cgi` on Debian, `images` on Alpine); they are safe to include and safe to leave out.

The plugin never writes SmokePing's files, so restoring the data directory cannot disagree with anything the plugin holds except through the mappings below.

## Restoring

1. Install Cacti, PHP, and SmokePing as in the [install guide](install.md), and the same plugin version or a newer one from the release archive.
2. Restore the Cacti database.
3. Restore SmokePing's configuration and data directory to the paths the backup used, and start SmokePing.
4. Mount the data directory read-only at the plugin's mount path ([install guide, step 3](install.md#3-mount-the-data-directory-read-only-inside-cactis-rra-path)).
5. Let the poller run, or run `php plugins/smokeping/poller_smokeping.php --force --inventory` once.

Nothing else is needed. If the plugin files are newer than the restored database, the plugin migrates its tables on the next page load or poller cycle and records its version only when every step succeeded.

What this restores, and why it works:

- **Mappings resolve to the same RRD files.** A data source's path is `<path_rra>/smokeping/<target path>.rrd`, relative to the mount, so it does not change when the host or the Cacti directory does. A target's identity is its path in SmokePing's tree, not a database id, and the restored SmokePing configuration has the same tree.
- **The reader resumes.** The reader's cache holds the last round it saw for each file; the next run reads each file's `lastupdate` and replaces the row. A round SmokePing wrote while Cacti was down is picked up by the first run, and the freshness of each series is computed from the file's own time, not from when the reader last looked.
- **Graphs render.** They are ordinary Cacti graphs over the restored data sources and files.

Check the result on **SmokePing Health**: the mount, the reader, and the inventory are OK after one poller cycle, and the SmokePing page lists the mapped targets with a current freshness.

### If the database comes back older than SmokePing's configuration

A target added to SmokePing after the database backup is not in the restored database. The next inventory finds it and lists it as unmapped. A target removed since is marked gone, and an administrator re-links or archives it as usual. Nothing is lost: the files are in the data directory, and the mapping is one click on **SmokePing Inventory**.

### If the data directory is restored without its newest rounds

SmokePing continues to write into the restored files from their last update. The gap in the graphs is the gap between the backup and the restore.

## Restore from the YAML export

When the Cacti database is lost and only the export remains, the plugin's targets and mappings can be brought back without it, and the data sources and graphs re-created:

1. Install Cacti and the plugin on the new host, restore SmokePing's configuration and data directory, and mount the data directory, as above. Set the paths on the Settings page (they are not imported; see the [format](configuration-format.md)).
2. Create the Cacti devices again with the **same host name and description** as before. Their ids may differ. The export names each mapped device by those two values.
3. Check and apply the export:

   ```sh
   php plugins/smokeping/cli/smokeping.php import --file=/var/backups/smokeping-config.yaml
   php plugins/smokeping/cli/smokeping.php import --file=/var/backups/smokeping-config.yaml --apply --confirm=<plan id>
   ```

   The check lists what would change and refuses a mapping whose device is missing or ambiguous. Nothing is written until the second command, which needs the plan id the first one printed.
4. For each mapped target, create its data sources and graphs again: `php plugins/smokeping/cli/smokeping.php provision <target path> --device=<device id>`. They point at the same files as before.

The export holds the plugin's settings, targets, and mappings. It holds no data source, graph, or RRD path, so step 4 is needed; the restore from a database backup does not need it.

## Uninstalling

Uninstalling writes a YAML export first (see the [install guide](install.md#upgrading-and-removing)) and never touches SmokePing's files, so an uninstall followed by a restore from that export works like the case above.

## Verification

`sh tests/run-restore-e2e.sh <work-dir>` runs both restores in fresh containers and databases: it provisions a device and data sources on a working installation, dumps the database and archives SmokePing's configuration and data directory, restores all three into new containers while SmokePing writes a new round, and checks that the plugin is enabled and its upgrade is a no-op, that each mapping, device, and data source path is the same, that every data source resolves under the mount to a file whose checksum matches the backup, that a graph renders, and that one poller cycle later the reader's cache holds the new round. It then restores from the YAML export alone onto a fresh database and checks that the mapping follows the device's name, that an inventory run keeps it, and that provisioning recreates data sources on the same files.
