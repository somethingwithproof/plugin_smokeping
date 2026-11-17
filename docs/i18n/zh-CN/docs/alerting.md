<!-- Source: docs/alerting.md; SHA256: 30514e9f4c05d71c7d9ae7406524d0fef0de3ca96907a7bc9c27842c1bc9ff72 -->

<a id="alerting-on-smokeping-data-with-thold-and-maint"></a>
# 使用 Thold 和 Maint 对 SmokePing 数据告警

插件不发送告警。它为每个目标位置向 Cacti 提供一个被轮询的“SmokePing Summary”数据源，Thold 和 Maint 像处理其他数据源一样处理它（[ADR 0002](../../../decisions/0002-alert-through-cacti-data-sources.md)）。这两个插件都无需更改。本页面向操作员，说明数据源报告的内容及此方法的边界。

<a id="what-the-data-source-reports"></a>
## 数据源报告的内容

每个摘要数据源由 Cacti 采集器通过脚本服务器轮询。脚本只读取读取器缓存中的一行：在 Cacti 轮询期间绝不打开 RRD 文件，也不联系 SmokePing。

| 数据源 | 含义 | 何时为 `U`（未知） |
|---|---|---|
| `median` | 最近一轮已完成测量的往返时间中位数，单位毫秒 | 此轮超过 SmokePing 心跳期限（`step` 的两倍）、RRD 缺失或从未更新，或该轮没有中位数（全部丢包） |
| `loss_pct` | 最近一轮已完成测量的 `loss / pings x 100` | 此轮超过心跳期限、RRD 缺失或从未更新，或该轮没有写入丢包值 |
| `age` | 距 RRD 上次更新的秒数 | RRD 不存在，或读取器没有对应缓存行 |

数据缺失时，`median` 和 `loss_pct` 绝不会是 `0`。因此停止的采集器不会显示为零丢包。只要 RRD 存在，`age` 就保持数值，即使其他两个已变为 `U`，因此可以针对过期告警。对于已创建但从未更新的文件，报告从插件首次发现目标起经过的时间。

从 `step + freshness allowance` 到 `step` 两倍之间的轮次为“延迟”：仍报告其值。全部丢包表现为 `loss_pct` 100 且 `median` 为 `U`。

摘要数据源必须由运行读取器的 Cacti 采集器（“SmokePing Poller”设置）轮询，因为缓存存储在该采集器的数据库中。插件拒绝在分配给其他采集器的设备上创建摘要数据源，并指出应使用的采集器。

<a id="thresholds"></a>
## 阈值

使用 Thold 模板或在各数据源上为 `loss_pct`、`median` 和 `age` 创建 Thold 阈值。典型值如下：

| 告警内容 | 阈值 | 备注 |
|---|---|---|
| 丢包 | `loss_pct` 高阈值为 5 至 10 | 全部丢包为 100 |
| 延迟 | `median` 高阈值为您的毫秒限制 | `U` 不产生延迟告警 |
| 采集器停止或缓慢 | `age` 高阈值为 `step` 的 2 至 3 倍 | 唯一能报告采集器停止的值 |

值为 `U` 时，`median` 或 `loss_pct` 阈值不会触发。请使用 `age` 判断“SmokePing 已停止测量”。

<a id="limits"></a>
## 限制

这些限制来自 Cacti 和 SmokePing 的采样方式，而非插件，插件无法消除它们。

**Thold 持续时间至少应为一个 `step`。** Cacti 按自己的间隔轮询，SmokePing 按自己的 `step` 测量。Cacti 间隔短于 `step` 时，同一轮会报告多次；长于它时，会跳过一些轮次。因此，一个超限样本并不等于一轮超限测量。将 Thold 触发持续时间设为至少一个 `step`（默认 300 秒步长和 300 秒采集间隔时，触发计数设为 2 次轮询）。短于 `step` 的持续时间可能因同一轮被计数两次而告警。

**延迟到达的从节点数据绝不会重新触发或清除告警。** SmokePing 从节点缓存未能送达的结果并重发，主节点按时间戳顺序应用它们，因此过去时间窗口的数据可能延迟到达。Thold 在轮询时评估值。迟到的轮次会更新 RRD 和图表，但不会更改 Thold 已评估的值，因此不会补发遗漏的告警，也不会清除已触发的告警。从节点落后时，该位置的 `age` 增长，使延迟可见。

**告警只看到摘要。** 中位数、丢包和年龄描述最后一轮。调查分布请使用烟雾图。

**SmokePing 自身的告警独立运行。** SmokePing 配置中的 `alerts` 部分仍然有效。若一个目标同时在 SmokePing 和 Thold 中告警，且符合两者条件，同一问题可能产生两次通知；插件不会关闭任何一方。健康页面根据清单读取的 `alerts` 值，在 `dual_alerting` 下列出这些目标，其继承方式与 SmokePing 相同。

**Cacti RRD 是副本。** 摘要值同时存储在 Cacti 和 SmokePing 自己的 RRD 中，因此两者的保留期或合并方式差异可能反映在图表中。

<a id="maintenance"></a>
## 维护

在目标设备上设置“Cacti device”类型的 Maint 计划，会抑制该设备的 Thold 通知。Thold 继续评估，插件继续填充摘要数据源，因此图表和 `age` 不会出现空缺。计划结束且仍超限时，需要再次达到触发计数才恢复通知：设备维护期间，Thold 不计入超限次数。

Maint 只识别设备，不识别单个目标。持有多个目标的设备会对所有目标静默。目标级计划需要新增 Maint 计划类型，不属于本插件范围。

<a id="verification"></a>
## 验证

`tests/run-alert-e2e.sh` 在容器内使用 Cacti 1.2.31 和插件工作区中的 `plugin_thold`、`plugin_maint` 副本运行整个路径：测试采集器向 SmokePing 布局的 RRD 追加轮次，读取器缓存它们，`poller.php` 通过脚本服务器轮询摘要数据源，然后 Thold 和 Maint 对其处理。它验证 `loss_pct` 阈值在达到触发计数后告警并恢复，Maint 设备计划在数据源仍被轮询时抑制通知，维护结束后告警触发，以及测试采集器停止时 `age` 阈值触发、恢复采集后恢复。
