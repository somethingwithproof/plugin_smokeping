<!-- Source: docs/operations.md; SHA256: 12e8fea195c9c3ab59312905bbe340b79a8c47ac405e9233953dbf8b583d545b -->

<a id="operating-the-smokeping-plugin"></a>
# SmokePing 插件运维

本页说明如何解读插件显示的内容、各状态含义和依据、异常时检查什么，以及输入失败时插件如何响应。安装见[安装](install.md)，告警见[告警](alerting.md)，备份见[备份与恢复](backup-restore.md)。

View、Operator 和 Admin 权限及个人分诊行为见[访问角色](../README.md#access-roles)。

<a id="pages"></a>
## 页面

| 页面 | 权限域 | 内容 |
|---|---|---|
| **SmokePing**（顶部标签） | View | 映射到可见设备的目标的最新状态，每目标、每位置一行 |
| **SmokePing Inventory** | Admin | 配置列出的全部 SmokePing 目标，无论是否映射，以及待处理重新关联建议 |
| **SmokePing Health** | Admin | 下列检查和重试模板导入按钮 |
| **SmokePing Configuration** | Admin | 插件配置导出与导入（[格式](configuration-format.md)） |
| **Import SmokePing Configuration**（在 Import/Export 下） | Admin | 将现有 SmokePing 配置导入为设备、图表及图表树（[方法](provisioning.md#a-whole-smokeping-configuration)） |

烟雾图和摘要图都是普通 Cacti 图表，受 Cacti 图表和设备权限控制。

<a id="what-each-state-means"></a>
## 各状态的含义

SmokePing 每行组合独立维度。它们绝不合为一种颜色，因为采集器停止和全部丢包需要不同响应。

| 维度 | 状态 | 依据 |
|---|---|---|
| 新鲜度 | `current`、`delayed`、`stale`、`never` | RRD 最后更新时间（由 `rrdtool lastupdate` 读取）距今时长，与目标 `step` 比较。Current：在一个 `step` 加新鲜度余量（默认 60 秒）内。Delayed：更久，但不超过 `step` 两倍，即 SmokePing 心跳期限。Stale：超过心跳期限，SmokePing 值已未知。Never：文件缺失或创建后从未更新 |
| 执行 | `completed`、`probe_error`、`unknown` | 写入数值丢包时，该轮完成。过期或从未观测的数据不能说明最后一轮，因此为 `unknown`。绝不设置 `probe_error`：失败探针写出的值与目标不响应相同（[研究](../../../research/probe-failures.md)） |
| 状况 | `within_policy`、`degraded`、`complete_loss`、`unknown` | 来自最后一轮完成的测量。`loss` 等于 `pings` 且无中位数为全部丢包。低于此值的任何丢包为退化。停止的采集器绝不显示为丢包：它变为过期，状况为 `unknown` |
| 管理 | `enabled`、`disabled`、`archived` | 插件中的目标记录。禁用或归档的目标不读取 |
| 维护 | 维护中或非维护中 | Maint 对目标设备的 `is_device_in_maintenance` 钩子，显示在目标页面 Maintenance 列。没有 Maint 或禁用时，所有设备显示不在维护中 |
| 测量时间 | 距最后更新的秒数 | RRD 自身时间戳。刷新页面绝不算作测量 |

每个位置的新鲜度来自自身文件（从节点为 `target~slave.rrd`），因此即使主节点当前，从节点落后也会显示过期。

丢包列显示最后一轮的 `lost/pings`，中位数以毫秒表示；`U` 表示未知，不是零。

<a id="health-checks"></a>
## 健康检查

| 检查 | OK 的含义 | 非 OK 时 |
|---|---|---|
| `php_binary` | Cacti 设置指定的 PHP 二进制存在且可执行 | 读取器无法启动：修正 Cacti 的“PHP Binary Path”设置 |
| `reader` | 上次运行在三个采集间隔内完成，且未超预算 | 警告：读取器延迟或超预算。序列多时提高预算或检查存储；见[性能](performance.md) |
| `inventory` | 上次清单成功解析 SmokePing 配置 | 错误：显示 SmokePing 解析器的文件、行号和消息。继续使用上一份清单 |
| `mount`、`read-only`、`writable` | 挂载路径在 RRA 内，不经过符号链接，只读挂载且不可写 | 错误。见[安装指南](install.md#3-mount-the-data-directory-read-only-inside-cactis-rra-path) |
| `visibility` | 清单中的文件通过挂载可见 | 警告，注明缺失数量 |
| `templates` | 已导入 SmokePing 模板 | 错误并给出原因；修复后使用 **Retry Template Import** |
| `device_pollers` | 所有具有摘要数据源的设备都由读取器所在采集器轮询 | 错误并列出设备：改由该采集器轮询之前，摘要值和告警没有数据 |
| `loss_colors` | Loss Colors 选择的丢包颜色：读取器发现的 SmokePing 版本颜色或指定颜色。更改后主采集器在后续周期迁移现有图表，每周期 200 个；`upgrade-graphs` 立即迁移。该行统计仍用其他颜色的图表，并警告迁移失败者 | 读取器未发现版本且设置为 Automatic 时未知：使用 2.8 及以后颜色。设置与发现版本不符时警告。见[丢包颜色](provisioning.md#loss-colors) |
| `dual_alerting` | 没有目标同时有 SmokePing `alerts` 值和摘要数据源上已启用的 Thold 阈值 | 警告列出最多 10 个目标：同时符合两方条件会通知两次。检查不比较条件；例如没有 SmokePing 丢包告警时，Thold `age` 阈值仍可能触发。删除 SmokePing 告警或禁用阈值前先比较。未安装 Thold 时为 OK |

<a id="typical-situations"></a>
## 常见情况

- **全部为 `stale`。** SmokePing 停止写入，或挂载已过期。检查 `systemctl status smokeping`，然后比较通过挂载和 SmokePing 主机上对相同文件执行 `rrdtool lastupdate` 的结果。只读 NFS 挂载可能因属性缓存时间而延迟。
- **一个从节点 `stale`，主节点 `current`。** 从节点停止投递。主节点在迟到结果到达时应用它们，因此图表回填；Thold 不重新评估（[告警](alerting.md)）。
- **目标显示 `never`。** RRD 尚不存在（SmokePing 在重载后首轮创建），或挂载未显示它。检查 `visibility` 结果。
- **Cacti 升级到 1.2.32 后图表为空。** RRA 之外的数据源路径会被 Cacti 生成的路径替换。确认挂载位于插件所述位置，且没有任何符号链接。
- **SmokePing 部分被重命名。** SmokePing 为新路径创建新文件。清单提出重新关联建议，在管理员确认前不更改（[预配](provisioning.md#re-linking-a-renamed-target)）。

<a id="topologies"></a>
## 拓扑

1. **SmokePing 位于 Cacti 采集器主机。** 采集器用户通过绑定挂载读取数据目录。安装指南描述此场景。
2. **SmokePing 数据目录共享给 Cacti 主机。** 在运行 SmokePing 采集器的主机 RRA 路径下，通过 NFS 只读挂载。健康页面将文件最后更新时间与时钟比较，因此滞后挂载显示 `delayed` 或 `stale`。烟雾图在提供 Web 界面的 Cacti 服务器渲染，所以该服务器也需要共享；仅采集器挂载时，摘要源和告警可用，但烟雾图为空。
3. **SmokePing 位于 Cacti 远程数据采集器** 属于后续阶段，本版本不支持。

只有 **SmokePing Poller** 指定的采集器读取 SmokePing 数据。摘要数据源所在设备必须由它轮询。

<a id="when-inputs-fail"></a>
## 输入失败时

以下是该版本经过验证的行为（[故障验证](../../../failure-qualification.md)）；均不会丢失数据。

| 故障 | 已记录行为 |
|---|---|
| SmokePing 配置不可读或损坏 | 清单失败并在 Health 显示解析器消息；保留上一份清单，不删除内容 |
| SmokePing 继续运行但探针无法执行：二进制错误退出、源地址不在主机上、fping 拒绝参数或 FPing6 的 IPv6 被禁用 | SmokePing 对该探针全部目标写入全部丢包，与无响应目标相同。测量显示 `complete_loss`。若一个探针的所有目标同时全部丢包，请在 SmokePing 日志查找探针错误 |
| SmokePing 检测到探针故障：二进制缺失、参数越界、fping 无原始套接字 | SmokePing 在启动或首轮退出。所有目标变为 `delayed`，随后 `stale`，与 SmokePing 停止相同 |
| 目标主机名无法解析（DNS 故障） | 探针失败并写入丢包；插件读取 SmokePing 所写内容。显示 `degraded` 或 `complete_loss`，不是过期。插件自身不解析名称 |
| SmokePing 或其采集器停止 | 新鲜度变为 `delayed` 再 `stale`；`median` 和 `loss_pct` 未知，`age` 持续增加。绝不为零丢包 |
| Cacti 与 SmokePing 主机时钟偏差 | 新鲜度比较 RRD 更新时间和 Cacti 时钟，偏差会平移所有年龄。SmokePing 领先时给轮次写入未来时间，显示 `current`；停止的采集器会保持 `current` 直到偏差时间过去。SmokePing 落后时所有序列看起来更旧，超过 `step` 两倍即 `stale`。两台主机均保持 NTP |
| RRD 不可读或损坏 | 读取器对文件记录警告并继续其他文件。从未读取的序列为未知（`U`）。之前读取过的保留缓存最后值，随时间经过 `delayed` 和 `stale` 变未知，因此文件损坏绝不变为零丢包 |
| Cacti 主机存储已满 | 读取器只写数据库缓存。写入被拒绝时正常退出，所有缓存行完整保持原样；存储恢复后下一周期缓存新轮次。摘要源的 Cacti RRD 与其他数据源一样失败 |
| 读取器运行中被终止 | 每个缓存行完整写入，因此已留下的行是完整读数。Cacti 检测到死进程，下一次运行立即开始并完成全部序列 |
| 第二个读取器启动 | Cacti 进程表允许每采集器一个读取器。重复进程立即以 0 退出并记录备注（Medium 日志级别）；不干扰第一个 |
| 维护开始与结束 | Maint 抑制设备通知；Thold 继续评估，摘要数据源继续轮询 |
| 插件升级中断 | 记录版本只在每步成功后更改；下次页面加载或采集周期完成升级 |

<a id="import-and-export"></a>
## 导入与导出

见[配置格式](configuration-format.md)。简写形式：

```sh
php plugins/smokeping/cli/smokeping.php export --file=/var/backups/smokeping-config.yaml
php plugins/smokeping/cli/smokeping.php import --file=/var/backups/smokeping-config.yaml
```

<a id="logs"></a>
## 日志

插件以 `SMOKEPING` 标签写入 Cacti 日志。`grep SMOKEPING log/cacti.log` 显示读取器统计（`SMOKEPING STATS`）、清单失败、模板导入失败以及每次已应用的配置导入。
