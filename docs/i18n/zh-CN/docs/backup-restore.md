<!-- Source: docs/backup-restore.md; SHA256: c0c5f5817de19e00bdaff571d69546d2c905683b183bfcffb3c7f776db9fa730 -->

<a id="backup-and-restore"></a>
# 备份与恢复

同时恢复三项内容后，Cacti 和 SmokePing 组合即可继续工作：Cacti 数据库、SmokePing 配置及 SmokePing 数据目录。本页说明每项保存什么、如何备份、恢复后做什么，以及数据库丢失时如何从 YAML 导出恢复插件自身配置。

<a id="what-to-back-up"></a>
## 备份内容

| 项目 | 保存内容 | 插件为何需要 |
|---|---|---|
| Cacti 数据库 | 插件表（目标、映射、创建的对象、读取器缓存、健康状态）、Cacti 设备、数据源、图表及插件设置 | 映射、数据源路径和图表定义存储在这里 |
| SmokePing 配置：`/etc/smokeping/config` **及它包含的每个文件**（`@include` 行、Debian 和 Ubuntu 的 `config.d/*`、`secrets` 文件、`smokemail`、`basepage.html`） | 目标树 | 清单解析它。目标身份是树中的路径，其 RRD 文件是 `datadir/<path>.rrd` |
| SmokePing 数据目录（`datadir`） | RRD 文件 | 每个图表和摘要值均读取它们；它们是历史记录，无法重建 |

插件设置存储在数据库中。[下文](#restore-from-the-yaml-export)所述导出是插件自身配置的第二份文本副本，不能替代上述三项中的任何一项。

<a id="taking-the-backups"></a>
## 执行备份

在大致相同的时间转储数据库并复制文件，使数据库中的目标与磁盘文件描述相同配置。

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

使用您的发行版的 `datadir`（EL 和 Fedora 为 `/var/lib/smokeping/rrd`）。SmokePing 持续写入 RRD 文件，运行期间复制可能取得正在更新中的文件。为获得一致副本，请使用文件系统或卷快照，或在复制期间停止 SmokePing（`systemctl stop smokeping`；这造成测量缺口，而不是丢失历史）。SmokePing 数据目录可能包含其他目录（Debian 的 `__cgi`、Alpine 的 `images`）；包含或省略它们都安全。

插件绝不写入 SmokePing 文件，因此恢复数据目录与插件所存内容的差异只可能来自下述映射。

<a id="restoring"></a>
## 恢复

1. 按[安装指南](install.md)安装 Cacti、PHP 和 SmokePing，并从发布归档安装同版本或更新的插件。
2. 恢复 Cacti 数据库。
3. 将 SmokePing 配置和数据目录恢复到备份时的路径，并启动 SmokePing。
4. 在插件挂载路径以只读方式挂载数据目录（[安装指南第 3 步](install.md#3-mount-the-data-directory-read-only-inside-cactis-rra-path)）。
5. 等待采集器运行，或执行一次 `php plugins/smokeping/poller_smokeping.php --force --inventory`。

无需其他步骤。如果插件文件比恢复的数据库更新，插件会在下次页面加载或采集周期迁移其表，并仅在每一步成功后记录版本。

恢复内容及其原理：

- **映射解析到相同的 RRD 文件。** 数据源路径为 `<path_rra>/smokeping/<target path>.rrd`，相对于挂载点，因此主机或 Cacti 目录改变时它不变。目标身份是其在 SmokePing 树中的路径，而不是数据库 ID；恢复后的配置具有相同树。
- **读取器恢复运行。** 读取器缓存保存每个文件最后看到的轮次；下次运行读取各文件的 `lastupdate` 并替换该行。Cacti 停机时 SmokePing 写入的轮次会在第一次运行时取得，每个序列的新鲜度根据文件自己的时间计算，而不是读取器上次查看的时间。
- **图表可以渲染。** 它们是基于已恢复数据源和文件的普通 Cacti 图表。

在 **SmokePing Health** 检查结果：一个采集周期后，挂载、读取器和清单均为 OK，SmokePing 页面列出映射目标并显示当前新鲜度。

<a id="if-the-database-comes-back-older-than-smokepings-configuration"></a>
### 恢复的数据库早于 SmokePing 配置时

数据库备份后新增的 SmokePing 目标不会出现在恢复的数据库中。下次清单会发现它并列为未映射。此后删除的目标标记为已消失，管理员像平常一样重新关联或归档。不会丢失任何内容：文件仍在数据目录中，在 **SmokePing Inventory** 单击即可映射。

<a id="if-the-data-directory-is-restored-without-its-newest-rounds"></a>
### 恢复的数据目录不含最新轮次时

SmokePing 从恢复文件的最后更新时间起继续写入。图表中的缺口就是备份与恢复之间的时间段。

<a id="restore-from-the-yaml-export"></a>
## 从 YAML 导出恢复

Cacti 数据库丢失且只剩导出时，可以不依赖数据库恢复插件目标和映射，并重新创建数据源与图表：

1. 在新主机安装 Cacti 和插件，恢复 SmokePing 配置和数据目录，并按上文挂载数据目录。在 Settings 页面设置路径（路径不导入；参见[格式](configuration-format.md)）。
2. 使用与之前**相同的主机名和描述**重新创建 Cacti 设备。ID 可以不同。导出通过这两个值标识每个映射设备。
3. 检查并应用导出：

   ```sh
   php plugins/smokeping/cli/smokeping.php import --file=/var/backups/smokeping-config.yaml
   php plugins/smokeping/cli/smokeping.php import --file=/var/backups/smokeping-config.yaml --apply --confirm=<plan id>
   ```

   检查会列出拟更改内容，并拒绝设备缺失或存在歧义的映射。第二条命令执行前不写入任何内容，它需要第一条命令打印的计划 ID。
4. 为每个映射目标重新创建数据源和图表：`php plugins/smokeping/cli/smokeping.php provision <target path> --device=<device id>`。它们指向原来的文件。

导出保存插件设置、目标和映射，不保存数据源、图表或 RRD 路径，因此需要第 4 步；从数据库备份恢复时不需要这一步。

<a id="uninstalling"></a>
## 卸载

卸载会先写入 YAML 导出（参见[安装指南](install.md#upgrading-and-removing)），绝不触碰 SmokePing 文件，因此卸载后从该导出恢复与上述情况相同。

<a id="verification"></a>
## 验证

`sh tests/run-restore-e2e.sh <work-dir>` 在全新容器和数据库中运行两种恢复：在可用安装中预配设备与数据源，转储数据库并归档 SmokePing 配置和数据目录，在 SmokePing 写入新一轮时将三者恢复到新容器，并检查插件已启用且升级无操作，每个映射、设备和数据源路径相同，每个数据源在挂载点下解析到校验和与备份一致的文件，图表可渲染，以及一个采集周期后读取器缓存包含新轮次。随后仅从 YAML 导出恢复到全新数据库，并检查映射跟随设备名称，清单运行后仍保留映射，预配能在相同文件上重建数据源。
