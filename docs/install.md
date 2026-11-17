# Installing the SmokePing plugin

This guide takes a Cacti 1.2 host and a SmokePing install from nothing to graphs in Cacti. It covers installing the plugin, the read-only access to SmokePing's data directory (the part that most often goes wrong), how to verify it, containers, and what is supported. Day-to-day operation is in [operations](operations.md), alerting in [alerting](alerting.md), backup in [backup and restore](backup-restore.md).

## What is supported

| Component | Supported | Tested here |
|---|---|---|
| Cacti | 1.2.31 and the current 1.2.x branch (1.2.32 changes how data source paths are checked; see the mount below) | 1.2.31 release and a 1.2.32 development copy |
| PHP | 8.2 or later. On PHP 8.0 and 8.1 the plugin refuses to install and Cacti keeps working | 8.2.35 for the integration and end-to-end runs; 8.0 syntax lint of the entry points |
| SmokePing | 2.9.0, 2.8.2, and 2.7.3 (Ubuntu 22.04 only, until 2027-06-01) | All three for the configuration parser, compared with SmokePing's own on 2.7.3 (Ubuntu 22.04), 2.8.2 (Debian 13), and 2.9.0 (Fedora 45); all three for graph fidelity |
| rrdtool | The version Cacti uses; the reader runs `path_rrdtool` | 1.7.2 |
| Thold, Maint | No change needed | the versions in the plugin workspace |
| Series count | 10,000 per Cacti installation at 20 pings and a 300 s step, on four dedicated vCPUs and 8 GB ([performance](performance.md#supported-series-count)) | 100, 1,000, and 10,000 on the release runner |

A "series" is one target at one measurement location (the master or a slave). Distribution support follows the vendors' full-support windows; see the [distribution matrix](research/distribution-matrix/README.md). Debian 13, Ubuntu 22.04 to 26.10, EL 9, Fedora 43 to 45, and Alpine 3.22 to 3.24 package SmokePing. **EL 10 and openSUSE Leap 16 have no SmokePing package**, so there the plugin works only to the extent a source install of SmokePing does, which this guide does not cover.

## How the pieces fit

SmokePing writes RRD files. Cacti graphs them and the plugin reads their latest values, and neither writes them. Cacti 1.2.32 and later refuse a data source path outside Cacti's RRA directory, so the plugin points Cacti at SmokePing's data directory through a **read-only mount inside Cacti's RRA path**, by default `<rra_path>/smokeping` ([decision 0007](decisions/0007-read-only-mount-under-rra-path.md)). The mount is also the main protection for SmokePing's files: Cacti's own cleanup cannot delete or rename through it.

The Cacti poller that runs the plugin's reader must be able to read two things on the SmokePing host:

1. SmokePing's configuration file (`/etc/smokeping/config`) and the files it includes, which the plugin reads with its own PHP parser of SmokePing's format. Included files must be in the configuration file's directory or below it; the plugin refuses any other include. The plugin runs no Perl and does not need SmokePing's Perl library.
2. SmokePing's data directory, through the mount.

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

**What else is in the data directory.** Debian's packaging keeps the slaves' caches in `DATADIR/__cgi/<section>/` (SmokePing's `dyndir`) and does not create the section directories itself, so a slave that has not reported yet leaves `__cgi/<section>/` empty or missing; Alpine keeps its image cache in `DATADIR/images`, and SmokePing's sorter caches are in `DATADIR/__sortercache`. The plugin ignores all of them: it never lists the data directory, and reads only the RRD files that SmokePing's configuration says exist (`<section>/<target>.rrd`, and `<target>~<slave>.rrd` for each slave), so neither these directories nor an RRD file that no target owns (for example `Local/LocalMachine.rrd`) is read, listed, or reported as an error. This was checked against a Debian 13 master with two slaves ([testing](testing.md#the-live-suite)).

## 2. Let the Cacti poller read SmokePing

On the packaged distributions the RRD files are world-readable and every directory above them is world-traversable, so the poller user needs **no group membership** to read them. The configuration file is world-readable on Debian, Ubuntu, and Alpine and `0640 root:apache` on EL and Fedora, where only `apache` (and root) can read it.

Check what the poller user can read, as that user (`www-data` on Debian and Ubuntu, `apache` on EL and Fedora):

<!-- verify:poller-access -->
```sh
runuser -u "$POLLER_USER" -- test -r "$DATADIR" && echo "datadir readable"
runuser -u "$POLLER_USER" -- test -r /etc/smokeping/config && echo "config readable"
runuser -u "$POLLER_USER" -- ls "$DATADIR" > /dev/null && echo "datadir listable"
```

Each line must print, and with the packaged permissions they do: the plugin needs no group membership and no other setup on the SmokePing host. A line fails only when SmokePing's permissions are tighter than the package's (for example `datadir` as `0750 smokeping:smokeping`), or when the poller runs as a user other than `apache` on EL and Fedora, where the configuration file is `0640 root:apache`. Then give that one user read access, for example with an ACL (`setfacl -R -m u:$POLLER_USER:rX` on the configuration directory and `DATADIR`), and never make SmokePing's files writable by Cacti's user.

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

The directory must be named `smokeping`. Run the last command as the user that owns Cacti's files (`apache` on EL). `--allperms` gives the console administrator all three plugin realms; other users receive **SmokePing View**, **SmokePing Operator**, or **SmokePing Admin** through User Management. Operator includes View access, and Admin includes Operator access. The plugin installs its tables and the SmokePing graph and host templates; if the template import fails it says so on the Health page and keeps working, and **Retry Template Import** there repeats it after you fix the cause.

On PHP older than 8.2 the install is refused with a message naming the required version, and the rest of Cacti is unaffected.

## 5. Configure and confirm

Open **Console > Configuration > Settings > SmokePing** and check:

| Setting | Meaning |
|---|---|
| SmokePing Poller | The Cacti poller that runs on, or shares a filesystem with, SmokePing. Only this poller reads SmokePing data. |
| SmokePing Configuration File | `/etc/smokeping/config` unless you moved it |
| SmokePing Data Directory | `DATADIR`, as SmokePing sees it |
| Cacti Mount of the Data Directory | The mount point from step 3; the default is `<rra_path>/smokeping` |
| Loss Colors | Automatic, unless SmokePing runs where the reader cannot see its `Smokeping.pm` and is 2.7: then SmokePing 2.7. See [loss colors](provisioning.md#loss-colors) |

Then open **SmokePing Health** on the server of the SmokePing poller. The configuration row reads the file at once and shows the number of targets, or the first error with its file and line. After the next poller cycle every row should be OK: the PHP binary, the configuration, the reader, the inventory, the mount, and the templates. To run the first inventory and reader at once instead of waiting for the poller:

```sh
sudo -u www-data php plugins/smokeping/poller_smokeping.php --force --inventory
```

**SmokePing Inventory** now lists every SmokePing target. Nothing is graphed yet; see [provisioning](provisioning.md) to add targets to Cacti.

## Languages

The plugin ships translations into Spanish (Spain, `es-ES`), French (`fr-FR`), German (`de-DE`), Simplified Chinese (`zh-CN`), Hindi (`hi-IN`), Arabic (`ar-SA`) and Japanese (`ja-JP`). Cacti shows them once language support is on (Configuration > Settings > General > Language Support) and a user picks the language. A Latin American Spanish catalog (`es-419`) ships as well, but Cacti 1.2 has no Latin American Spanish locale to select, so it takes effect only when Cacti adds one; until then, choose Spanish (`es-ES`).

## Containers

Run the reader where it can see both things from the start of this guide.

- **Cacti in a container, SmokePing on the host.** Mount the data directory read-only at the RRA path, and the configuration directory read-only too:

  ```sh
  docker run -d --name cacti \
    -v /var/lib/smokeping:/var/www/html/rra/smokeping:ro \
    -v /etc/smokeping:/etc/smokeping:ro \
    my-cacti-image
  ```

  Replace the data mount's left-hand side with the host's `DATADIR`. The image needs nothing from SmokePing: no Perl and no `smokeping` package. The container's PHP must be 8.2 or later. The `:ro` on the mount is what the Health page checks; a mount without it is reported as an error.
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

Replace the plugin directory with the new release and open the Plugins page or wait for the next poller cycle; the plugin migrates its tables and templates and records the new version only after every step succeeds, so an interrupted upgrade is finished by the next one. Disabling the plugin stops its jobs and leaves SmokePing's files alone. Uninstalling writes a YAML export of the plugin's configuration first, to the Backup Directory setting or else `cache/smokeping/` in the Cacti directory (random file name, mode `0600`, and the path is shown to you), drops the plugin's tables unless **Keep Plugin Tables on Uninstall** is set, and never touches SmokePing's files or configuration.
