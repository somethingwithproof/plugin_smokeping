<!-- Source: docs/configuration-format.md; SHA256: ec68c633a93e30aa23abef57fd66184cc2e8857c66333a82ce211fc72938f7c1 -->

<a id="configuration-file-format"></a>
# 配置文件格式

插件自身配置使用一种格式导入和导出，提供两种表示：YAML（默认）和 XML。本页定义架构版本 1。设计原因见[决策 0008](../../../decisions/0008-yaml-and-xml-configuration-files.md)。

文件包含插件设置、插件已读取的 SmokePing 目标（观测目标）及每个目标映射到的 Cacti 设备。它不包含数据源、图表、RRD 路径或凭据。Cacti 的图表、数据和主机模板是另一回事，仍采用 Cacti XML。

<a id="commands"></a>
## 命令

```sh
php plugins/smokeping/cli/smokeping.php export                         # YAML to standard output
php plugins/smokeping/cli/smokeping.php export --format=xml --file=/var/backups/smokeping-config.xml
php plugins/smokeping/cli/smokeping.php import --file=smokeping-config.yaml           # check only
php plugins/smokeping/cli/smokeping.php import --file=smokeping-config.yaml --apply --confirm=<plan>
```

`export --file` 创建仅所有者可读的新文件，拒绝覆盖现有文件。不带 `--apply` 的 `import` 读取并检查文件，打印所有拟更改内容及计划 ID，不写入任何内容。`--apply` 需要带该 ID 的 `--confirm`；它在单个事务中应用，如果检查后文件或安装发生变化则拒绝。退出状态：0 完成，1 拒绝或失败，2 文件或计划有错误。

在 **Management > SmokePing Configuration**（SmokePing Admin 权限域）可执行相同操作：下载导出，选择文件检查，查看所列更改，然后应用。

导入仅添加和更新。它绝不删除目标、将映射移到其他设备（请在 Inventory 页面重新关联）、创建数据源、图表或 RRD 文件，也不读写 SmokePing 数据目录。卸载将配置的 YAML 导出写入 **Backup Directory** 设置指定的位置；若该设置为空，则写入 Cacti 目录中的 `cache/smokeping/`。文件使用随机名称和 `0600` 权限，默认目录含拒绝所有访问的 `.htaccess` 和不提供任何内容的 `index.php`。若 Backup Directory 不存在或不可写，则不写导出（插件不会退回默认目录），卸载仍继续，并报告文件写入位置或未写入文件。请选择 Web 根目录之外的目录。

<a id="schema-version-1"></a>
## 架构版本 1

```yaml
schema_version: 1            # required; a higher number is refused

settings:                    # optional; any subset of the settings below
  smokeping_poller_id: 1
  smokeping_reader_budget: 120
  smokeping_inventory_interval: 300
  smokeping_freshness_allowance: 60
  smokeping_uninstall_keep_tables: false

reference_settings:          # optional; shown for reference, never imported
  smokeping_config_file: /etc/smokeping/config
  smokeping_backup_dir: ''
  smokeping_datadir: /var/lib/smokeping
  smokeping_mount_path: /var/www/html/cacti/rra/smokeping   # omitted from exports while it is the default

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

身份。目标由 `poller_id` 和 `path` 标识。设备由 `hostname` 和 `description` 标识；没有设备或多个设备具有该组合时，检查拒绝映射。导入会去除文本两端空格，因为插件读取所有存储值时也会这样处理。

保留键。`managed_targets` 和 `probe_profiles` 留给将来增加托管模式的版本。版本 1 以指出键名的错误拒绝它们，使为未来版本编写的文件不会被部分应用。后续版本通过它们扩展架构；只有更改此处现有键的含义时才提高 `schema_version`。

更新。对于已存在目标，导入只更改 `host`、`probe`、`menu`、`title`、`slaves`、`nomasterpoll`、`step`、`pings` 和 `admin_state`。前六个字段仍以 SmokePing 配置为源，下次清单运行会恢复它们。因此请用导入恢复 `admin_state` 或初始化全新安装，不要用它编辑目标。

设置值必须为指定类型。文本不得包含控制字符。

仅供参考的设置。指定文件或目录的设置（上述 `reference_settings` 中的全部设置）绝不通过导入写入：文件不得更改插件读写的位置。架构通过类型标识它们，将其中一个置于 `settings` 的文件会被拒绝。在 `reference_settings` 中，它们会被检查格式（绝对路径，或允许时为空）、与安装比较，有差异时在计划中列为 `not applied, change on the Settings page`。请在 Settings 页面更改。1.0.0-rc.1 导出的文件还在此列出 `smokeping_perl_binary` 和 `smokeping_lib_dir`；这些设置已不存在，导入会跳过。

<a id="xml"></a>
## XML

```xml
<?xml version="1.0" encoding="UTF-8"?>
<smokeping-config schema_version="1">
  <settings>
    <setting name="smokeping_poller_id">1</setting>
    <setting name="smokeping_uninstall_keep_tables">false</setting>
  </settings>
  <reference_settings>
    <setting name="smokeping_datadir">/var/lib/smokeping</setting>
  </reference_settings>
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

XML 与上述 YAML 键一一对应。布尔值为 `true` 或 `false`。读取器拒绝任何 DOCTYPE 或实体声明，不加载外部资源，且只接受这些元素。

<a id="yaml-subset"></a>
## YAML 子集

读取器接受块映射、块列表、普通标量、单引号及双引号标量、注释、`|` 和 `|-` 字面块，并可在开头使用一个可选的 `---`。双引号标量接受转义 `\\ \" \/ \n \t \r \0 \b \f \e \xHH \uHHHH`；单引号标量通过重复引号（`''`）转义。带引号标量在自身所在行闭合。

以下内容会被拒绝并报告行号：锚点（`&x`）、别名（`*x`）、标签（`!x`）、流集合（`[a, b]`、`{a: b}`）、折叠块（`>`）、多个文档、指令、合并键（`<<`）、复杂键（`?`）、重复键、用作缩进的制表符、多行普通标量及超过 8 层的嵌套。对于其他 YAML 版本会解释为非文本的普通标量也会拒绝：`yes`、`no`、`on`、`off`、`y`、`n`、前导零数字、小数、十六进制、日期以及 `1:30` 等时间。请加引号（`'yes'`）。`true`、`false`、`null`、`~` 和普通整数保留其含义。

导出器会为读取器可能误解析的每个字符串加引号，包括含冒号或 `#`、首尾空格或首字符被 YAML 视为指示符的字符串。

<a id="limits"></a>
## 限制

两种格式的文件均限制为 8 MiB 和 8 层嵌套，每行限制为 16 KiB。读取前先检查文件大小。
