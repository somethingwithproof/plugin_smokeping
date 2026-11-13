# Decision records

Each record captures one architecture decision: its context, the decision, its consequences, and the alternatives rejected. Records are numbered and never renumbered. A reversed decision gets a new record that supersedes the old one.

| Record | Decision | Status |
|---|---|---|
| [0001](0001-observe-mode-first.md) | Ship read-only observe mode first, in phases that each release | Accepted |
| [0002](0002-alert-through-cacti-data-sources.md) | Alert through polled Cacti data sources, not a plugin alert engine | Accepted |
| [0003](0003-render-smoke-with-cacti-graph-templates.md) | Render smoke graphs with Cacti graph templates over SmokePing's RRD files | Accepted, pending fidelity evidence; path amended by 0007 |
| [0004](0004-use-cacti-remote-collectors.md) | Reach remote SmokePing hosts through Cacti remote data collectors, not a custom bridge | Accepted |
| [0005](0005-never-reuse-managed-target-ids.md) | Use random, never-reused section names for managed targets | Accepted |
| [0006](0006-php-82-floor-with-80-parse-guard.md) | Require PHP 8.2, keep entry points parseable on PHP 8.0 | Accepted |
| [0007](0007-read-only-mount-under-rra-path.md) | Expose SmokePing's datadir through a read-only mount under `rra_path` | Accepted; amends 0003 |

Open decisions are tracked as issues labelled `type: decision`.
