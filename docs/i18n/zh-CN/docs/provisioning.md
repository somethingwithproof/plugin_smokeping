<!-- Source: docs/provisioning.md; SHA256: 3a7e1fea196c12380b832900900588d2e2f745bcd71a27980c49bdb268d119c6 -->

<a id="adding-smokeping-targets-to-cacti"></a>
# 向 Cacti 添加 SmokePing 目标

清单发现的每个目标在 **SmokePing Inventory** 中对管理员可见，在映射到 Cacti 设备前对其他人不可见。未经管理员确认不创建任何内容。插件绝不写入 SmokePing 文件。

<a id="one-target"></a>
## 单个目标

在 SmokePing Inventory 中，存在且未映射的目标有 **Add to Cacti** 链接。确认界面提供两种选择：

- **创建新设备**，使用“SmokePing Target”模板。设备使用目标主机，关闭可用性检查（SmokePing 已测量），并分配给运行读取器的采集器。
- **映射到现有设备。** 下拉列表包含您可以更改的设备。

无论哪种方式，目标均获得用于告警的摘要数据源和图表（见[告警](alerting.md)），且当 SmokePing 数据可绘图时，每个位置有一张烟雾图。若 `pings` 不是 10、20 或 30，Cacti 在 RRDproxy 存储 RRD，或文件在只读挂载中不可见，则没有烟雾图，页面说明原因。此目标仍保留摘要数据。

摘要源由运行读取器的采集器轮询。现有设备由其他采集器轮询时，插件不创建摘要源，并指出应使用的采集器。健康页面列出后来被移至其他采集器的设备。

<a id="many-devices"></a>
## 多个设备

在设备列表选择设备并选 **Add SmokePing Targets**。确认界面对每设备列出 SmokePing `host` 与设备主机名相同（不区分大小写）的未映射目标，以及总匹配数。继续即添加。无匹配设备会被报告并保持原样，不创建设备。本地容器中一次请求添加 100 个设备约耗时 24 秒；极长选择会在请求时限前停止启动新设备，再运行即可完成，因为已添加设备会跳过。

<a id="a-whole-smokeping-configuration"></a>
## 整份 SmokePing 配置

**Console > Import/Export > Import SmokePing Configuration** 一步将现有 SmokePing 安装引入 Cacti。需要 SmokePing Admin 权限域及覆盖全部设备的权限；镜像到图表树还需要 Trees 权限域。

选择输入：

- **已配置文件**（默认）：SmokePing Configuration File 设置，在服务器读取并包含其所有引用文件。包含文件必须在该目录或下层目录。
- **上传文件**，最大 4 MiB。独立读取，因此不允许 `@include`，会带行号拒绝。目标使用读取器采集器可见的 RRD 绘图；下次清单会将该采集器 SmokePing 中不存在的目标标记为缺失。

然后选择选项：

| 选项 | 默认 | 含义 |
|---|---|---|
| 设备 | 每 SmokePing 主机一个 | 探测相同主机的目标共用设备。每目标一个则各有独立设备，以其标题命名。 |
| 设备模板 | SmokePing Target | 导入创建的设备模板。 |
| 采集器 | 读取器的采集器 | 必须运行读取器；其他采集器被拒绝，因为其摘要源得不到数据。 |
| 站点 | 无 | 新设备的站点。 |
| 图表树 | 不镜像 | 现有树或按名称创建新树。每个 SmokePing 部分成为分支，每设备作为主机项位于其目标所在分支下。 |

**Preview** 读取配置但不写入。显示每个 SmokePing 部分及复选框（选择部分并点 **Update Preview** 以只导入这些目标）、待创建或复用设备、待创建图表、仅获得摘要数据的目标及原因（ping 次数非 10/20/30，或 RRDproxy）、树分支与项，以及与批量操作相同的预计负载。所选采集器上若设备主机名匹配目标则复用（每目标一个设备时，描述还须匹配标题）；两个设备匹配时，跳过并列出这些目标，需在 SmokePing Inventory 映射。**Import** 通过 Cacti 设备、图表和树函数精确应用预览计划。预览后若配置或 Cacti 改变，不写任何内容，需重新预览。

重复导入不创建新内容：已映射目标保留设备，复用图表和树项，结果列出各目标已在 Cacti 中。导入绝不写 SmokePing 文件或挂载。

使用相同选项的命令行导入：

```sh
php plugins/smokeping/cli/smokeping.php import-smokeping --dry-run --tree-name=SmokePing
php plugins/smokeping/cli/smokeping.php import-smokeping --apply --confirm=PLAN --tree-name=SmokePing
```

`--file=PATH` 像上传一样独立读取另一个文件。`--sections=Europe,Lab/Core`、`--device-per=target`、`--device-template=ID`、`--poller=ID`、`--site=ID` 和 `--tree=ID` 设置其他选项。试运行打印计划及 ID；`--apply` 需要此 ID 且仍须匹配。

<a id="graph-scale"></a>
## 图表尺度

SmokePing 每次渲染根据数据调整图表。Cacti 图表对所有时间范围只有一个上限，因此插件对最后一天应用 SmokePing 详细图规则（`Smokeping.pm` 的 `findmax`）：图表文件最后一天最大中位数的 1.2 倍。SmokePing Navigator Graph 使用 1.5 倍；插件没有导航图。烟雾图创建时调整，之后约每日调整。最后一天无中位数则保留当前上限；新图保留模板的 0.05 秒（50 ms）。

每日调整默认开启，无设置选项。每次读取后，读取器调整其采集器目标中距上次调整至少一天的烟雾图，等待最久者优先，每周期最多 25 个。每图读取一个 RRD，不运行探针。读取耗尽预算的周期会跳过。五分钟一个周期可每天覆盖 7,200 图；更多时，每图频率略低于每日一次。

插件记录每图设定的上限。与记录不同的上限视为在图表页面手动设置，插件绝不更改。要交回控制，将其恢复为插件最后设置的值；`size-graphs` 对每个手动上限图表打印该值。

立即调整所有烟雾图：

```sh
php plugins/smokeping/cli/smokeping.php size-graphs
```

它打印每图新上限、保留原上限或保留手动上限。手动上限规则与每日调整相同。需要 Cacti rrdtool 路径（Configuration > Settings > Paths）；缺少时保留上限。

从 1.0.0-rc.1 升级时，没有记录说明已有上限是谁设置的，因此插件将每图当前上限视为自己的，在下次每日轮次调整。此前 `size-graphs` 也会覆盖手动上限。仅当上限不同于插件记录时才算手动，升级后记录值就是采纳值。要保留手动上限，请在每日调整替换后重新设置。若该次调整没有找到中位数，会保留采纳值；再次设置相同值无变化，之后的调整仍可能替换。

<a id="graph-legend-and-template-upgrades"></a>
## 图例和模板升级

烟雾图图例遵循 SmokePing：中位数的平均、最大、最小、最后值，标准差，am/s（平均除以标准差），随后是丢包值及颜色、探针行，例如 `probe: 20 ICMP Echo Pings (56 Bytes) every 300s`。探针行来自清单从配置读取的目标探针、包大小、ping 次数和步长。主采集器写入每图，并在目标探针改变或模板导入重置后重写。清单尚未读取探针前存储的目标显示探针名称。

升级带来新图表模板时，旧模板创建的图表保留旧项且仍可渲染，直到插件迁移。主采集器每周期处理 200 图，保留标题和上限。立即处理所有图：

```sh
php plugins/smokeping/cli/smokeping.php upgrade-graphs
```

升级按模板设置图表项，因此手动修改的插件图表项会被替换。随后插件检查项与模板完全一致且使用的每个 VDEF 都存在。失败图保留旧项与旧版本，Cacti 日志注明名称。采集器不重试。`upgrade-graphs` 每次运行重新尝试每个失败图，若仍有失败则退出 1。

<a id="loss-colors"></a>
## 丢包颜色

SmokePing 2.7 使用七个固定丢包等级；2.8 及以后按 ping 比例分级并增加全部丢包等级。插件为两者分发基于相同数据源的烟雾图模板，均匹配 SmokePing 原生图表（[图表保真度](../../../research/graph-fidelity.md)）。设置中的 **Loss Colors** 选择：

- **Automatic**（默认）跟随读取器在 SmokePing 附近的 `Smokeping.pm`（`/usr/share/perl5`、`/usr/share/smokeping`、`/opt/smokeping/lib` 或 `/usr/local/smokeping/lib`）发现的版本，每次清单记录。低于 2.8 使用 2.7 颜色；找不到版本（如 SmokePing 在另一容器）则使用 2.8 及以后。
- **SmokePing 2.8 and later** 或 **SmokePing 2.7** 固定颜色，不论读取器发现什么。

健康页 `loss_colors` 行显示当前颜色和原因。设置或 SmokePing 升级导致颜色改变时，主采集器每周期迁移 200 图到其他模板，保留标题、上限和数据。`upgrade-graphs` 立即迁移全部。失败图保留颜色，由下次 `upgrade-graphs` 重试，如上所述。

<a id="re-linking-a-renamed-target"></a>
## 重新关联已重命名目标

SmokePing 根据目标在配置树中的位置命名文件，因此重命名或移动节点会创建新空文件。导入发现某路径消失且出现相同主机和探针的新路径时，记录建议但不更改。清单页 **Review** 打开确认界面。重新关联将烟雾图、摘要源及设备映射移到新目标，同时在归档图表保留旧文件历史可读。**Dismiss Proposal** 关闭建议而不更改。

<a id="permissions"></a>
## 权限

插件有三个权限域。**SmokePing View** 允许打开 SmokePing 页面（只列出映射到用户可见设备的目标），并从设备图表链接进入。**SmokePing Operator** 添加 Operations 页面及当前观测的个人确认；它不改变测量健康状态，也不抑制告警。**SmokePing Admin** 包含 Operator 访问权限，并打开清单、健康页及上述操作。设备权限受限的管理员可见未映射目标及其可见设备的目标。

图表和数据源是普通 Cacti 对象，由 Cacti 图表和设备权限决定谁可见。Cacti 默认权限方式是宽松的：只有设备、图表和图表模板策略全部拒绝时，用户才受限于部分设备。权限更改在用户下一请求生效。

<a id="deleting-a-device"></a>
## 删除设备

删除已映射设备会移除映射及插件的数据源记录，将目标留在清单中且未映射。SmokePing 文件不受影响：见 [ADR 0007](../../../decisions/0007-read-only-mount-under-rra-path.md)。
