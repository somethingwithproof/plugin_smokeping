<!-- Source: docs/install.md; SHA256: 0d075ea04065badbbe1a97434609b979a10cb760b6f483e6cb7f8bc4768a214e -->

<a id="installing-the-smokeping-plugin"></a>
# 安装 SmokePing 插件

本指南将 Cacti 1.2 主机和 SmokePing 安装从零配置到 Cacti 图表，涵盖插件安装、对 SmokePing 数据目录的只读访问（最常出错的部分）、验证、容器及支持范围。日常运维见[运维](operations.md)，告警见[告警](alerting.md)，备份见[备份与恢复](backup-restore.md)。

<a id="what-is-supported"></a>
## 支持范围

| 组件 | 支持 | 此处测试 |
|---|---|---|
| Cacti | 1.2.31 和当前 1.2.x 分支（1.2.32 改变数据源路径检查，见挂载说明） | 1.2.31 发布版和 1.2.32 开发副本 |
| PHP | 8.2 或更新。8.0/8.1 拒绝安装，Cacti 继续工作 | 集成及端到端测试用 8.2.35；入口点用 8.0 语法检查 |
| SmokePing | 2.9.0、2.8.2、2.7.3（仅 Ubuntu 22.04，至 2027-06-01） | 三者均测配置解析器，与 2.7.3（Ubuntu 22.04）、2.8.2（Debian 13）、2.9.0（Fedora 45）原生解析器比较；三者均测图表保真度 |
| rrdtool | Cacti 所用版本；读取器执行 `path_rrdtool` | 1.7.2 |
| Thold、Maint | 无需更改 | 插件工作区版本 |
| 序列数 | 每安装 10,000 条，20 次 ping、300 秒步长、4 个专用 vCPU 和 8 GB（[性能](performance.md#supported-series-count)） | 发布运行器上 100、1,000、10,000 条 |

“序列”是一个目标在一个测量位置（主节点或从节点）。发行版支持遵循供应商完整支持期；见[发行版矩阵](../../../research/distribution-matrix/README.md)。Debian 13、Ubuntu 22.04 至 26.10、EL 9、Fedora 43 至 45、Alpine 3.22 至 3.24 提供 SmokePing 包。**EL 10 和 openSUSE Leap 16 没有 SmokePing 包**，因此仅在源码安装可用的范围内工作，本指南不覆盖源码安装。

<a id="how-the-pieces-fit"></a>
## 组件如何配合

SmokePing 写入 RRD，Cacti 绘图，插件读取最新值，后两者均不写入。Cacti 1.2.32 及以后拒绝 RRA 目录之外的数据源路径，因此插件通过 **Cacti RRA 路径内的只读挂载**访问 SmokePing 数据目录，默认为 `<rra_path>/smokeping`（[决策 0007](../../../decisions/0007-read-only-mount-under-rra-path.md)）。挂载也是主要保护：Cacti 清理功能无法通过它删除或重命名。

运行读取器的 Cacti 采集器必须能读取 SmokePing 主机上两项内容：

1. 配置文件（`/etc/smokeping/config`）及包含文件，由插件自身 PHP 解析器读取。包含文件必须在配置目录或下层，其他路径拒绝。插件不运行 Perl，也不需要 SmokePing Perl 库。
2. 通过挂载读取数据目录。

若位于不同主机，以只读 NFS 共享数据并挂载到运行 SmokePing 采集器的主机；见[运维](operations.md#topologies)。在 Cacti 远程数据采集器上运行 SmokePing 属于后续阶段，本版本不支持。

<a id="1-install-smokeping-if-it-is-not-installed"></a>
## 1. 安装 SmokePing（如尚未安装）

Debian 13 和 Ubuntu：

<!-- verify:install-smokeping-deb -->
```sh
apt-get update
DEBIAN_FRONTEND=noninteractive apt-get install -y smokeping
```

EL 9（包在 EPEL，需要 CodeReady Builder）：

<!-- verify:install-smokeping-el -->
```sh
dnf install -y epel-release
dnf config-manager --set-enabled crb
dnf install -y smokeping
```

照常配置 SmokePing，用 `systemctl enable --now smokeping` 启动。数据目录在 Debian、Ubuntu、Alpine 为 `/var/lib/smokeping`，EL、Fedora 为 `/var/lib/smokeping/rrd`。下文 `DATADIR` 指该目录（配置 `*** Database ***` 中的 `datadir`），`RRA` 指 Cacti RRA 路径（Console > Configuration > Settings > Paths 的“RRA Path”；示例为 `/var/www/html/cacti/rra`）。

**目录中其他内容。** Debian 将从节点缓存存于 `DATADIR/__cgi/<section>/`（SmokePing `dyndir`），不自行创建部分目录，因此尚未报告的从节点对应 `__cgi/<section>/` 为空或缺失；Alpine 图片缓存位于 `DATADIR/images`，排序器缓存位于 `DATADIR/__sortercache`。插件全部忽略：它绝不列出数据目录，只读取配置声明的 RRD（`<section>/<target>.rrd` 和各从节点 `<target>~<slave>.rrd`）。这些目录及无目标拥有的 RRD（如 `Local/LocalMachine.rrd`）都不读取、不列出、不报错。已在 Debian 13 主节点和两个从节点验证（[测试](../../../testing.md#the-live-suite)）。

<a id="2-let-the-cacti-poller-read-smokeping"></a>
## 2. 允许 Cacti 采集器读取 SmokePing

发行版包的 RRD 对所有人可读，所有父目录可遍历，因此采集器用户**无需加入组**。配置在 Debian、Ubuntu、Alpine 对所有人可读，在 EL、Fedora 为 `0640 root:apache`，仅 `apache` 和 root 可读。

以采集器用户身份检查可读性（Debian/Ubuntu 为 `www-data`，EL/Fedora 为 `apache`）：

<!-- verify:poller-access -->
```sh
runuser -u "$POLLER_USER" -- test -r "$DATADIR" && echo "datadir readable"
runuser -u "$POLLER_USER" -- test -r /etc/smokeping/config && echo "config readable"
runuser -u "$POLLER_USER" -- ls "$DATADIR" > /dev/null && echo "datadir listable"
```

每行必须打印，包默认权限下确实如此：无需组成员资格或 SmokePing 主机上其他设置。只有权限严于软件包（如 `datadir` 为 `0750 smokeping:smokeping`），或 EL/Fedora 使用非 `apache` 用户时才失败。此时只给予该用户读取权，例如对配置目录和 `DATADIR` 使用 ACL（`setfacl -R -m u:$POLLER_USER:rX`），绝不让 Cacti 用户可写 SmokePing 文件。

<a id="3-mount-the-data-directory-read-only-inside-cactis-rra-path"></a>
## 3. 在 Cacti RRA 路径内只读挂载数据目录

选择以下一种。两者均创建绑定挂载，需要使用 root 一次。

<a id="option-a-an-etcfstab-line"></a>
### 选项 A：`/etc/fstab` 行

<!-- verify:mount-fstab -->
```sh
mkdir -p "$RRA/smokeping"
echo "$DATADIR  $RRA/smokeping  none  bind,ro  0 0" >> /etc/fstab
mount "$RRA/smokeping"
```

立即生效并在每次启动生效。若用普通 `mount --bind` 测试，还需重新挂载，因为旧 `util-linux` 首次绑定忽略 `ro`：`mount -o remount,bind,ro "$RRA/smokeping"`。

<a id="option-b-a-systemd-mount-unit"></a>
### 选项 B：systemd `.mount` 单元

文件名须为挂载路径中 `/` 变成 `-`；让 systemd 计算：

<!-- verify:mount-unit -->
```sh
unit=$(systemd-escape --path --suffix=mount "$RRA/smokeping")
cat > "/etc/systemd/system/$unit" <<EOF
[Unit]
Description=Read-only view of SmokePing's data directory for Cacti

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

若依赖此挂载的服务可能先启动，在其单元使用 `RequiresMountsFor=`，例如在 `php-fpm.service` 附加配置中设置 `RequiresMountsFor=/var/www/html/cacti/rra/smokeping`。

SmokePing 在其他主机时，替换为只读 NFS（`smokeping-host:/var/lib/smokeping  $RRA/smokeping  nfs  ro,nfsvers=4.2  0 0`），并由该主机只读导出目录。

<a id="verify-the-mount"></a>
### 验证挂载

<!-- verify:mount-verify -->
```sh
findmnt -no TARGET,SOURCE,OPTIONS "$RRA/smokeping"
findmnt -no OPTIONS "$RRA/smokeping" | tr ',' '\n' | grep -x ro
touch "$RRA/smokeping/.cacti-write-test" && echo "WRITABLE: fix the mount before continuing" || echo "read-only, as required"
ls "$RRA/smokeping" | head
```

必须出现 `ro`，`touch` 必须以 `Read-only file system` 失败（即使 root），列表必须显示 SmokePing 部分。Health 从 `/proc/self/mountinfo` 重复检查，将可写挂载、缺少挂载或路径中符号链接报告为**错误**。符号链接不能替代挂载：Cacti 1.2.32 及以后拒绝经过符号链接的数据源路径。

<a id="4-install-the-plugin"></a>
## 4. 安装插件

从发布归档安装（见[打包](../../../release.md)）：

```sh
tar -xzf smokeping-0.2.0.tar.gz -C /var/www/html/cacti/plugins
chown -R root:root /var/www/html/cacti/plugins/smokeping
cd /var/www/html/cacti
sudo -u www-data php cli/plugin_manage.php --plugin=smokeping --install --enable --allperms
```

目录必须名为 `smokeping`。以 Cacti 文件所有者（EL 为 `apache`）运行最后命令。`--allperms` 给控制台管理员三个插件权限域；其他用户由 User Management 获得 **SmokePing View**、**SmokePing Operator** 或 **SmokePing Admin**。Operator 包含 View 访问权限，Admin 包含 Operator 访问权限。插件安装表以及图表和主机模板；模板导入失败时在 Health 显示并继续工作，修复原因后可用 **Retry Template Import** 重试。

PHP 低于 8.2 时拒绝安装并提示所需版本，Cacti 其他部分不受影响。

<a id="5-configure-and-confirm"></a>
## 5. 配置与确认

打开 **Console > Configuration > Settings > SmokePing** 并检查：

| 设置 | 含义 |
|---|---|
| SmokePing Poller | 与 SmokePing 同主机或共享文件系统的 Cacti 采集器。只有它读取数据。 |
| SmokePing Configuration File | `/etc/smokeping/config`，除非已移动 |
| SmokePing Data Directory | SmokePing 所见的 `DATADIR` |
| Cacti Mount of the Data Directory | 第 3 步挂载点；默认 `<rra_path>/smokeping` |
| Loss Colors | Automatic；若读取器看不到 SmokePing 的 `Smokeping.pm` 且版本为 2.7，则选 SmokePing 2.7。见[丢包颜色](provisioning.md#loss-colors) |

在 SmokePing 采集器服务器打开 **SmokePing Health**。配置行立即读取文件，显示目标数或第一个错误的文件与行号。下次采集后每行应为 OK：PHP、配置、读取器、清单、挂载、模板。要立即运行而不等待：

```sh
sudo -u www-data php plugins/smokeping/poller_smokeping.php --force --inventory
```

**SmokePing Inventory** 现在列出全部目标。尚未绘图；见[预配](provisioning.md)以添加到 Cacti。

<a id="languages"></a>
## 语言

插件包含西班牙西班牙语（`es-ES`）、法语（`fr-FR`）、德语（`de-DE`）、简体中文（`zh-CN`）、印地语（`hi-IN`）、阿拉伯语（`ar-SA`）和日语（`ja-JP`）。启用语言支持（Configuration > Settings > General > Language Support）且用户选择语言后显示。还包含拉丁美洲西班牙语（`es-419`），但 Cacti 1.2 无此可选区域，因此需 Cacti 添加后才生效；此前请选择西班牙语（`es-ES`）。

<a id="containers"></a>
## 容器

让读取器从开始就能访问本指南所述两项内容。

- **Cacti 在容器，SmokePing 在主机。** 将数据只读挂载到 RRA 路径，配置目录也只读挂载：

  ```sh
  docker run -d --name cacti \
    -v /var/lib/smokeping:/var/www/html/cacti/rra/smokeping:ro \
    -v /etc/smokeping:/etc/smokeping:ro \
    my-cacti-image
  ```

  将数据挂载左侧替换为主机的 `DATADIR`。镜像不需要 SmokePing 内容：无需 Perl 或 `smokeping` 包。容器 PHP 必须 8.2 或更新。Health 检查挂载的 `:ro`；缺少则报告错误。
- **Docker Compose。** 相同配置，使用带 `read_only: true` 和 `type: bind` 的 `volumes:` 项：

  ```yaml
  services:
    cacti:
      volumes:
        - type: bind
          source: /var/lib/smokeping
          target: /var/www/html/cacti/rra/smokeping
          read_only: true
  ```

- **Kubernetes。** `volumeMounts` 中设置 `readOnly: true`，后端为 SmokePing pod 写入的 hostPath、NFS 或 CSI 卷。
- **SmokePing 在容器。** 将其 `datadir` 放在与 Cacti 主机共享的卷，按上文只读挂载到 Cacti。插件无需进入 SmokePing 容器。

<a id="cactis-rrd-cleaner-will-list-smokeping-files"></a>
## Cacti RRD 清理器会列出 SmokePing 文件

清理器（**Utilities > RRD Cleaner**）遍历整个 RRA，因此将没有 Cacti 数据源的 SmokePing RRD（包括从节点 `target~slave.rrd`）列为未使用并提供删除。不要选择它们。通过只读挂载删除或归档会失败，Cacti 记录警告并移除队列项，文件得以保留；但警告造成干扰，可写挂载则会丢数据。插件在清除前移除可识别队列项（每次采集开始扫描），使警告少见。清理器无排除列表，选择文件前请阅读列表。

<a id="upgrading-and-removing"></a>
## 升级与移除

用新版替换插件目录，打开 Plugins 页面或等待下一采集周期；插件迁移表及模板，仅在每步成功后记录新版，因此下一次会完成中断升级。禁用停止任务，不动 SmokePing 文件。**卸载前，请自行导出配置，并验证备份可读且完整。** 卸载会尝试将插件配置导出为 YAML，写入 Backup Directory 或 Cacti 的 `cache/smokeping/`（随机文件名、模式 `0600`，成功后显示路径）。如果导出失败，会记录并显示警告，但卸载仍会继续。除非设置 **Keep Plugin Tables on Uninstall**，否则删除插件表；绝不触碰 SmokePing 文件或配置。
