# What a probe writes when it fails

Research for [#22](https://github.com/somethingwithproof/plugin_smokeping/issues/22), recorded 2026-11-15. The question: what do FPing, FPing6 and TCPPing write to the RRD when the probe itself fails, as opposed to the target not answering? The execution state "probe error" of the [measurement state](../PLAN.md#measurement-state) needs that evidence.

## Method

`tests/research/probe-failures.sh` runs SmokePing from the distribution packages: 2.7.3 (Ubuntu 22.04), 2.8.2 (Debian 13) and 2.9.0 (Fedora 45). Each probe and failure runs in its own container, with step 20 and 3 pings and one target. After five steps the script records:

- whether the daemon is still running;
- the target's `rrdtool lastupdate`;
- its last rounds from `rrdtool fetch`;
- the log lines that name the failure.

The control for each probe pings the loopback address and gets loss 0 and a median.

TCPPing needs the third-party `tcpping` script, which no package ships. So only the two failures that do not depend on it were run: a missing binary, and a binary that exits with an error.

## Results

The three versions behave the same except in one row, noted below.

| Probe | Failure | SmokePing | What the RRD shows | Log |
|---|---|---|---|---|
| FPing, FPing6, TCPPing | `binary` does not exist | Refuses the configuration and exits (status 2) before any round | No update: the file is never written, or an existing file stops updating | `ERROR: <probe> 'binary' does not point to an executable` |
| FPing | `packetsize` above 64000 | Refuses the configuration and exits (status 255) | No update | `FPing packetsize must be between 12 and 64000` |
| FPing, FPing6 | No raw socket (`NET_RAW` dropped, no unprivileged ICMP) | Exits (status 1) at the first round | No update | `open3: exec of <fping> ... failed: Operation not permitted` |
| FPing, FPing6, TCPPing | The binary exits with an error (`/bin/false`) | Keeps running | `loss` = pings; `median` and every `pingN` unknown | None for FPing; the round is simply empty |
| FPing, FPing6 | `sourceaddress` not on the host | Keeps running | `loss` = pings; `median` and every `pingN` unknown | `fping: cannot bind source address`, `returned with exit code 1024` |
| FPing | `tos = 0x1ff` | Keeps running | 2.7.3 and 2.8.2: normal rounds, because their fping accepts the value. 2.9.0: `loss` = pings, the rest unknown | 2.9.0: fping prints its usage |
| FPing | The host name does not resolve | Keeps running | `loss` = pings; `median` and every `pingN` unknown | `WARNING: Hostname '...' does currently not resolve`, `returned with exit code 512` |
| FPing6 | IPv6 disabled on the host | Keeps running | `loss` = pings; `median` and every `pingN` unknown | fping reports `::1 : - - -`, as for a lost ping |

`uptime` is unknown in every round of every row, including the controls. These probes never write it.

## Findings

1. **A probe that fails while SmokePing runs writes complete loss.** The rounds are byte for byte what an unreachable target writes (`tests/run-smokeping-e2e.sh` pings 192.0.2.1 and gets the same values). No data source tells the two apart, in any probe or version tested.
2. **A failure that SmokePing detects stops it.** A missing binary or an invalid parameter is caught when the configuration is read, and a missing raw socket on the first exec. Either way the daemon exits, so the file stops updating and goes delayed, then stale, or it is never written.
3. **The only record of the cause is SmokePing's log.** The plugin reads the RRD files and the configuration, never SmokePing's log, so in observe mode it cannot set "probe error".

## What the plugin does with this

- **Execution state** comes from freshness and the round's `loss`: `completed` when the last round wrote a numeric loss, `unknown` when the data is stale or never observed. `probe_error` is never set from an RRD, because no RRD value shows it.
- **Complete loss** is reported as `complete_loss`. To an operator that means the target did not answer, or the probe could not run. The [operations guide](../operations.md) says so and points to SmokePing's log.
- **A probe failure that stops SmokePing** shows as `stale` or `never`, like any stopped collector, never as loss.
