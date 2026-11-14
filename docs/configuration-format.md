# Configuration file format

The plugin's own configuration exports to and imports from one file format, in two spellings: YAML (the default) and XML. This page defines schema version 1. The reasons for the design are in [decision 0008](decisions/0008-yaml-and-xml-configuration-files.md).

The file holds the plugin's settings, the SmokePing targets the plugin has read (observed targets), and which Cacti device each target is mapped to. It holds no data source, graph, RRD path, or credential. Cacti's graph, data, and host templates are a different thing and stay Cacti XML.

## Commands

```sh
php plugins/smokeping/cli/smokeping.php export                         # YAML to standard output
php plugins/smokeping/cli/smokeping.php export --format=xml --file=/var/backups/smokeping-config.xml
php plugins/smokeping/cli/smokeping.php import --file=smokeping-config.yaml           # check only
php plugins/smokeping/cli/smokeping.php import --file=smokeping-config.yaml --apply --confirm=<plan>
```

`export --file` creates a new file readable by its owner only and refuses to overwrite one. `import` without `--apply` reads and checks the file, prints every change it would make and a plan id, and writes nothing. `--apply` needs `--confirm` with that plan id; it applies in one transaction, and refuses if the file or the installation changed since the check. Exit status: 0 done, 1 refused or failed, 2 the file or plan has errors.

The same actions are on **Management > SmokePing Configuration** (SmokePing Admin realm): download an export, then choose a file to check, review the listed changes, and apply.

An import only adds and updates. It never deletes a target, never moves a mapping to another device (re-link on the Inventory page), never creates a data source, graph, or RRD file, and never reads or writes SmokePing's data directory. The uninstall writes a YAML export to `cache/smokeping/` in the Cacti directory.

## Schema version 1

```yaml
schema_version: 1            # required; a higher number is refused

settings:                    # optional; any subset of the settings below
  smokeping_config_file: /etc/smokeping/config
  smokeping_perl_binary: /usr/bin/perl
  smokeping_lib_dir: ''
  smokeping_datadir: /var/lib/smokeping
  smokeping_mount_path: /var/www/html/cacti/rra/smokeping   # omitted from exports while it is the default
  smokeping_poller_id: 1
  smokeping_reader_budget: 120
  smokeping_inventory_interval: 300
  smokeping_freshness_allowance: 60
  smokeping_uninstall_keep_tables: false

targets:                     # optional
  - path: Europe/Paris       # required; the SmokePing tree path
    poller_id: 1             # default 1; must exist
    host: paris.example.net
    probe: FPing
    menu: Paris              # optional text
    title: 'Paris: gateway'  # optional text
    slaves:                  # optional list of slave names
      - site-b
    nomasterpoll: false
    step: 300                # 0 to 604800
    pings: 20                # 0 to 10000
    mode: observed           # only observed
    admin_state: enabled     # enabled, disabled, or archived
    present: true            # honored for a new target only

mappings:                    # optional
  - target: Europe/Paris     # a target in this file or already in the installation
    poller_id: 1
    device:
      hostname: paris-gw.example.net   # the Cacti device's host name (not case sensitive)
      description: Paris gateway       # and its description (exact)
```

Identity. A target is identified by `poller_id` and `path`. A device is identified by `hostname` and `description`; the check refuses a mapping when no device, or more than one, has that pair. The import trims surrounding space from text, because the plugin reads every stored value trimmed.

Reserved keys. `managed_targets` and `probe_profiles` are reserved for a later release that adds managed mode. Version 1 refuses them with an error naming the key, so a file written for that release is never half applied. A later release extends this schema with them; it raises `schema_version` only if it changes the meaning of a key that exists here.

Updates. For a target already present, an import changes only `host`, `probe`, `menu`, `title`, `slaves`, `nomasterpoll`, `step`, `pings`, and `admin_state`. SmokePing's configuration stays the source for the first six fields: the next inventory run restores them, so use an import to restore `admin_state` and to seed a fresh installation, not to edit targets.

Settings values must be the stated type. Paths must be absolute (`smokeping_lib_dir` may be empty). Text may not contain control characters.

## XML

```xml
<?xml version="1.0" encoding="UTF-8"?>
<smokeping-config schema_version="1">
  <settings>
    <setting name="smokeping_poller_id">1</setting>
    <setting name="smokeping_uninstall_keep_tables">false</setting>
  </settings>
  <targets>
    <target path="Europe/Paris" poller_id="1" host="paris.example.net" probe="FPing"
            title="Paris: gateway" nomasterpoll="false" step="300" pings="20"
            mode="observed" admin_state="enabled" present="true">
      <slave>site-b</slave>
    </target>
  </targets>
  <mappings>
    <mapping target="Europe/Paris" poller_id="1">
      <device hostname="paris-gw.example.net" description="Paris gateway"/>
    </mapping>
  </mappings>
</smokeping-config>
```

The XML maps one to one onto the YAML keys above. Booleans are `true` or `false`. The reader refuses any DOCTYPE or entity declaration, does not load external resources, and accepts only these elements.

## YAML subset

The reader accepts block maps, block lists, plain, single-quoted and double-quoted scalars, comments, and `|` and `|-` literal blocks, with an optional single leading `---`. Double-quoted scalars take the escapes `\\ \" \/ \n \t \r \0 \b \f \e \xHH \uHHHH`; single-quoted scalars double the quote (`''`). A quoted scalar closes on its own line.

It refuses, with the line number: anchors (`&x`), aliases (`*x`), tags (`!x`), flow collections (`[a, b]`, `{a: b}`), folded blocks (`>`), multiple documents, directives, merge keys (`<<`), complex keys (`?`), duplicate keys, tabs used as indentation, multi-line plain scalars, and nesting deeper than 8 levels. It refuses a plain scalar that another YAML version reads as something other than text: `yes`, `no`, `on`, `off`, `y`, `n`, numbers with a leading zero, decimals, hex, dates, and times such as `1:30`. Quote them (`'yes'`). `true`, `false`, `null`, `~`, and plain whole numbers keep their meaning.

The exporter quotes every string a reader could misparse, including any with a colon or `#`, a leading or trailing space, or a first character that YAML treats as an indicator.

## Limits

Files are limited to 8 MiB and to 8 levels of nesting in both formats, and a line to 16 KiB. The size is checked before the file is read.
