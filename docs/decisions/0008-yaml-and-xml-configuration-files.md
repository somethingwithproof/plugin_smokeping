# 0008. Export and import the plugin's configuration as YAML or XML, YAML by default

Status: Accepted (2026-11-14)

## Context

The plugin keeps its own configuration in Cacti's database: settings, the SmokePing targets it has read, and the mapping of each target to a Cacti device. Three needs follow from that. An uninstall must leave something an administrator can restore from (docs/PLAN.md, "Storage, jobs, upgrades, and recovery"). A backup and restore test (#45) needs a form of the configuration that does not depend on database ids, because a restored Cacti may number its devices differently. And managed mode (milestone M5) needs a declarative input that an administrator can write and review before anything reaches SmokePing's configuration.

Cacti already uses XML for graph, data, and host templates, and `import_xml_data()` reads nothing else.

## Decision

- The plugin's configuration exports and imports as **YAML or XML**. **YAML is the default** for the command line, the admin page, and the uninstall export. XML carries the same content for sites that standardize on it.
- One schema, version 1, documented in `docs/configuration-format.md`. It holds the plugin settings, observed targets with their identity fields (`poller_id`, `path`, `host`, `probe`) and descriptive fields, and device mappings. A mapping names its device by **host name and description**, never by id.
- Settings that name a path or a program are reference only: an export shows them, an import compares them and never writes them.
- The keys `managed_targets` and `probe_profiles` are reserved for M5 and refused in version 1. M5 adds them to the same schema as declarative input and raises the version only when it changes the meaning of an existing key.
- YAML is read by a **strict subset parser** in `lib/` (`YamlReader`) and written by a matching emitter (`YamlWriter`). The parser accepts block maps, block lists, plain, single-quoted and double-quoted scalars, comments, and `|` and `|-` literal blocks. It rejects anchors, aliases, tags, flow collections, folded blocks, multiple documents, directives, merge keys, duplicate keys, tabs as indentation, and any plain scalar that another YAML version would read as a non-string (`yes`, `no`, `on`, `off`, `007`, `1.5`, dates). Errors name the line. The emitter quotes every string a reader could misparse.
- XML is read with `DOMDocument` with `LIBXML_NONET` and without `LIBXML_NOENT` or `LIBXML_DTDLOAD`; any DOCTYPE is refused. Only the schema's elements are accepted.
- File size (8 MiB) and nesting depth (8 levels) are bounded in both formats.
- Import validates the whole file and plans every change before writing. It is a dry run by default and shows the plan with an id. It applies only when an administrator confirms that plan's id, in one transaction, and refuses if the file or the database changed between the check and the apply. It only adds and updates: it never deletes a target, never moves a mapping to another device, never creates a data source, graph, or RRD file, and never touches SmokePing's mount.
- The CLI (`cli/smokeping.php export|import --format=yaml|xml`) and an admin page in the SmokePing Admin realm (`smokeping_config.php`, posts protected by Cacti's CSRF token) use the same service.
- The uninstall writes a YAML export before the tables go, to the Backup Directory setting or, when blank, to `cache/smokeping/` (which holds a deny-all `.htaccess` and an `index.php` that serves nothing). Cacti's uninstall hook cannot prompt, so the export is automatic, has a random name, is readable by its owner only, and the path is shown to the administrator. A Backup Directory that is not writable means no export, never a fall back. A failure is logged and does not block the uninstall. This replaces the plan's "uninstalling offers an export".
- **Graph, data, and host templates stay Cacti XML.** `import_xml_data()` accepts only that format and the plugin ships its templates through it (ADR 0003). The YAML and XML files in this record describe the plugin's own configuration, not Cacti templates.

## Consequences

- A backup of the plugin's own state is a text file that can be reviewed, diffed, and kept in version control, and it restores onto a Cacti whose device ids differ.
- Device identity by name means two devices with the same host name and description are ambiguous; the check says so and refuses the mapping until one is renamed.
- A hand-written subset parser is code the plugin maintains. It is small, has no dependency, runs no code, and has a deterministic fuzz test. The alternative below carries more risk than that.
- YAML files written by this plugin read the same under YAML 1.1 and 1.2 parsers, because the emitter quotes the scalars on which those versions disagree. A file written by hand with a bare `yes` is refused rather than guessed at.
- The export carries no data source, graph, or RRD path. A restore re-creates the mappings; the data sources and graphs come back with the Cacti database backup or from `cli/smokeping.php provision`.

## Alternatives rejected

- **A full YAML library.** The plugin adds no Composer runtime dependency (docs/PLAN.md, Code structure), and a full YAML 1.2 implementation is large, supports anchors, aliases, tags, and merge keys that an untrusted import must refuse anyway, and has a record of unsafe-deserialization bugs in other languages.
- **`yaml_parse()` from the PECL extension.** Not installed with Cacti's required extensions; the plugin cannot count on it.
- **JSON.** Machine-friendly but has no comments, which an administrator reviewing a managed-target file needs. It remains easy to add later because the schema is format-neutral.
- **Database ids for device mappings.** Break on restore onto a Cacti that numbers devices differently.
