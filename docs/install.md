# Installing the SmokePing plugin

This guide takes a Cacti 1.2 host and a SmokePing install from nothing to graphs in Cacti. It covers installing the plugin, the read-only access to SmokePing's data directory (the part that most often goes wrong), how to verify it, containers, and what is supported. Day-to-day operation is in [operations](operations.md), alerting in [alerting](alerting.md), backup in [backup and restore](backup-restore.md).

## What is supported

| Component | Supported | Tested here |
|---|---|---|
| Cacti | 1.2.31 and the current 1.2.x branch (1.2.32 changes how data source paths are checked; see the mount below) | 1.2.31 release and a 1.2.32 development copy |
| PHP | 8.2 or later. On PHP 8.0 and 8.1 the plugin refuses to install and Cacti keeps working | 8.2.35 for the integration and end-to-end runs; 8.0 syntax lint of the entry points |
| SmokePing | 2.9.0, 2.8.2, and 2.7.3 (Ubuntu 22.04 only, until 2027-06-01) | 2.8.2 (Debian 13), 2.9.0 (Alpine 3.24, Fedora 44, EL 9 EPEL) for the inventory; all three for graph fidelity |
| rrdtool | The version Cacti uses; the reader runs `path_rrdtool` | 1.7.2 |
| Thold, Maint | No change needed | the versions in the plugin workspace |
| Series count | See [performance](performance.md) for the measured and the supported number | |

A "series" is one target at one measurement location (the master or a slave). Distribution support follows the vendors' full-support windows; see the [distribution matrix](research/distribution-matrix/README.md). Debian 13, Ubuntu 22.04 to 26.10, EL 9, Fedora 43 to 45, and Alpine 3.22 to 3.24 package SmokePing. **EL 10 and openSUSE Leap 16 have no SmokePing package**, so there the plugin works only to the extent a source install of SmokePing does, which this guide does not cover.

## How the pieces fit

SmokePing writes RRD files. Cacti graphs them and the plugin reads their latest values, and neither writes them. Cacti 1.2.32 and later refuse a data source path outside Cacti's RRA directory, so the plugin points Cacti at SmokePing's data directory through a **read-only mount inside Cacti's RRA path**, by default `<rra_path>/smokeping` ([decision 0007](decisions/0007-read-only-mount-under-rra-path.md)). The mount is also the main protection for SmokePing's files: Cacti's own cleanup cannot delete or rename through it.

The Cacti poller that runs the plugin's reader must be able to read three things on the SmokePing host:

1. SmokePing's configuration file (`/etc/smokeping/config`), which the inventory reads with SmokePing's own parser.
2. SmokePing's Perl library (`Smokeping.pm`), because that parser is part of it.
3. SmokePing's data directory, through the mount.

When SmokePing and Cacti are on different hosts, share the data directory read-only (NFS) and mount it on the host that runs the SmokePing poller; see [operations](operations.md#topologies). SmokePing on a Cacti remote data collector is a later phase and is not supported by this release.

## 1. Install SmokePing (if it is not installed)

On Debian 13 and Ubuntu:

<!-- verify:install-smokeping-deb -->
```sh
apt-get update
DEBIAN_FRONTEND=noninteractive apt-get install -y smokeping
```

On EL 9 (the package is in EPEL and needs CodeReady Builder):

<!-- verify:install-smokeping-el -->
```sh
dnf install -y epel-release
dnf config-manager --set-enabled crb
dnf install -y smokeping
```

Configure SmokePing as usual and start it with `systemctl enable --now smokeping`. The data directory is `/var/lib/smokeping` on Debian, Ubuntu, and Alpine and `/var/lib/smokeping/rrd` on EL and Fedora. Below, `DATADIR` is that directory (the `datadir` of the `*** Database ***` section of SmokePing's configuration) and `RRA` is Cacti's RRA path (Console > Configuration > Settings > Paths, "RRA Path"; `/var/www/html/cacti/rra` in the examples).

## 2. Let the Cacti poller read SmokePing

On the packaged distributions the RRD files are world-readable and every directory above them is world-traversable, so the poller user needs **no group membership** to read them. The configuration file is world-readable on Debian, Ubuntu, and Alpine and `0640 root:apache` on EL and Fedora, where only `apache` (and root) can read it.

Check what the poller user can read, as that user (`www-data` on Debian and Ubuntu, `apache` on EL and Fedora):

<!-- verify:poller-access -->
```sh
runuser -u "$POLLER_USER" -- test -r "$DATADIR" && echo "datadir readable"
runuser -u "$POLLER_USER" -- test -r /etc/smokeping/config && echo "config readable"
runuser -u "$POLLER_USER" -- ls "$DATADIR" > /dev/null && echo "datadir listable"
```

Each line must print. If your SmokePing runs with tighter permissions (for example `datadir` as `0750 smokeping:smokeping`), give the poller user read access with a group, not with a mode change on SmokePing's files:

<!-- verify:poller-group -->
```sh
GROUP=$(stat -c %G "$DATADIR")
usermod -aG "$GROUP" "$POLLER_USER"
id "$POLLER_USER"
```

The group is the one that owns SmokePing's data directory: `smokeping` on Debian, Ubuntu, and Alpine, `apache` on EL and Fedora (which have no `smokeping` group). Restart the web server and whatever runs Cacti's poller (cron needs no restart; `php-fpm` and `httpd` do) so the new group applies. The configuration file on EL and Fedora is `0640 root:apache`, so a poller that runs as any user other than `apache` needs the `apache` group for it too. Never make SmokePing's files writable by Cacti's user.

## 3. Mount the data directory read-only inside Cacti's RRA path

Pick one of the two forms. Both create a bind mount, which needs root once.

### Option A: an `/etc/fstab` line

<!-- verify:mount-fstab -->
```sh
mkdir -p "$RRA/smokeping"
echo "$DATADIR  $RRA/smokeping  none  bind,ro  0 0" >> /etc/fstab
mount "$RRA/smokeping"
```

The mount takes effect at once and again at every boot. If you test with a plain `mount --bind`, add a remount, because older `util-linux` ignores `ro` on the first bind: `mount -o remount,bind,ro "$RRA/smokeping"`.

### Option B: a systemd `.mount` unit

The unit's file name must be the mount point's path with `/` turned into `-`; let systemd compute it:

<!-- verify:mount-unit -->
```sh
unit=$(systemd-escape --path --suffix=mount "$RRA/smokeping")
cat > "/etc/systemd/system/$unit" <<EOF
[Unit]
Description=Read-only view of SmokePing's data directory for Cacti
After=local-fs.target

[Mount]
What=$DATADIR
Where=$RRA/smokeping
Type=none
Options=bind,ro

[Install]
WantedBy=multi-user.target
EOF
systemd-analyze verify "/etc/systemd/system/$unit"
systemctl daemon-reload
systemctl enable --now "$unit"
```

Use `RequiresMountsFor=` in the units of services that need the mount, for example `RequiresMountsFor=/var/www/html/cacti/rra/smokeping` in a drop-in of `php-fpm.service`, if they can start before it.

For SmokePing on another host, replace the bind with a read-only NFS mount (`smokeping-host:/var/lib/smokeping  $RRA/smokeping  nfs  ro,nfsvers=4.2  0 0`), and export the directory read-only from that host.

### Verify the mount

<!-- verify:mount-verify -->
```sh
findmnt -no TARGET,SOURCE,OPTIONS "$RRA/smokeping"
findmnt -no OPTIONS "$RRA/smokeping" | tr ',' '\n' | grep -x ro
touch "$RRA/smokeping/.cacti-write-test" && echo "WRITABLE: fix the mount before continuing" || echo "read-only, as required"
ls "$RRA/smokeping" | head
```

The `ro` option must appear, the `touch` must fail with `Read-only file system` (even as root), and the listing must show SmokePing's sections. The plugin's Health page repeats the check from `/proc/self/mountinfo` and reports a writable mount, a missing mount, or a symbolic link in the path as an **error**. A symbolic link does not work as a substitute for the mount: Cacti 1.2.32 and later reject data source paths that go through one.

## 4. Install the plugin

From a release archive (see [packaging](release.md)):

```sh
tar -xzf smokeping-0.2.0.tar.gz -C /var/www/html/cacti/plugins
chown -R root:root /var/www/html/cacti/plugins/smokeping
cd /var/www/html/cacti
sudo -u www-data php cli/plugin_manage.php --plugin=smokeping --install --enable --allperms
```

The directory must be named `smokeping`. Run the last command as the user that owns Cacti's files (`apache` on EL). `--allperms` gives the console administrator both plugin realms; other users get **SmokePing View** and **SmokePing Admin** from User Management. The plugin installs its tables and the SmokePing graph and host templates; if the template import fails it says so on the Health page and keeps working, and **Retry Template Import** there repeats it after you fix the cause.

On PHP older than 8.2 the install is refused with a message naming the required version, and the rest of Cacti is unaffected.

## 5. Configure and confirm

Open **Console > Configuration > Settings > SmokePing** and check:

| Setting | Meaning |
|---|---|
| SmokePing Poller | The Cacti poller that runs on, or shares a filesystem with, SmokePing. Only this poller reads SmokePing data. |
| SmokePing Configuration File | `/etc/smokeping/config` unless you moved it |
| Perl Binary and Perl Library Directory | The interpreter and the directory holding `Smokeping.pm`. Leave the library blank to search the packaged locations (`/usr/share/smokeping`, and Perl's default path on Debian). |
| SmokePing Data Directory | `DATADIR`, as SmokePing sees it |
| Cacti Mount of the Data Directory | The mount point from step 3; the default is `<rra_path>/smokeping` |

Then open **SmokePing Health**. After the next poller cycle every row should be OK: the PHP binary, the reader, the inventory, the mount, and the templates. To run the first inventory and reader at once instead of waiting for the poller:

```sh
sudo -u www-data php plugins/smokeping/poller_smokeping.php --force --inventory
```

**SmokePing Inventory** now lists every SmokePing target. Nothing is graphed yet; see [provisioning](provisioning.md) to add targets to Cacti.

## Containers

Run the reader where it can see all three things from the start of this guide.

- **Cacti in a container, SmokePing on the host.** Mount the data directory read-only at the RRA path, and the configuration and library read-only too:

  ```sh
  docker run -d --name cacti \
    -v /var/lib/smokeping:/var/www/html/rra/smokeping:ro \
    -v /etc/smokeping:/etc/smokeping:ro \
    -v /usr/share/smokeping:/usr/share/smokeping:ro \
    my-cacti-image
  ```

  Replace the mounts' left-hand sides with the host's `DATADIR`, and set the plugin's Perl Library Directory to `/usr/share/smokeping` on EL, Fedora, and Alpine hosts (Debian and Ubuntu keep `Smokeping.pm` in Perl's default path, `/usr/share/perl5`; mount that directory or install the `smokeping` package in the image instead). The image also needs `perl` and the modules SmokePing's parser loads, which the `smokeping` package pulls in. The container's PHP must be 8.2 or later. The `:ro` on the mount is what the Health page checks; a mount without it is reported as an error.
- **Docker Compose.** The same, as `volumes:` entries with `read_only: true` and `type: bind`:

  ```yaml
  services:
    cacti:
      volumes:
        - type: bind
          source: /var/lib/smokeping
          target: /var/www/html/rra/smokeping
          read_only: true
  ```

- **Kubernetes.** A volume with `readOnly: true` in the `volumeMounts` entry, backed by a hostPath or an NFS or CSI volume that the SmokePing pod writes.
- **SmokePing in a container.** Put its `datadir` on a volume shared with Cacti's host and mount it read-only into Cacti as above. The plugin never needs to reach into SmokePing's container.

## Cacti's RRD cleaner will list SmokePing files

Cacti's RRD cleaner (**Utilities > RRD Cleaner**) walks the whole RRA path, so it lists SmokePing's RRD files that have no Cacti data source, including a slave's `target~slave.rrd`, as unused and offers to delete them. Do not select them. Deleting or archiving through the read-only mount fails, Cacti logs a warning, and the queue entry is dropped, so the files survive, but the warnings are noise and a writable mount would lose data. The plugin removes the queued entries it can recognize before the purge runs (it sweeps the queue at the start of every poller run), which keeps the warnings rare. The cleaner has no exclusion list, so read its list before you select files.

## Upgrading and removing

Replace the plugin directory with the new release and open the Plugins page or wait for the next poller cycle; the plugin migrates its tables and templates and records the new version only after every step succeeds, so an interrupted upgrade is finished by the next one. Disabling the plugin stops its jobs and leaves SmokePing's files alone. Uninstalling writes a YAML export of the plugin's configuration to `cache/smokeping/` in the Cacti directory first, drops the plugin's tables unless **Keep Plugin Tables on Uninstall** is set, and never touches SmokePing's files or configuration.
