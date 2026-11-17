<!-- Source: docs/configuration-format.md; SHA256: ec68c633a93e30aa23abef57fd66184cc2e8857c66333a82ce211fc72938f7c1 -->

<a id="configuration-file-format"></a>
# صيغة ملف الإعداد

تُصدّر إعدادات الإضافة الخاصة وتُستورد بصيغة ملف واحدة ذات تمثيلين: YAML (الافتراضي) وXML. تعرّف هذه الصفحة إصدار المخطط 1. أسباب التصميم في [القرار 0008](../../../decisions/0008-yaml-and-xml-configuration-files.md).

يحفظ الملف إعدادات الإضافة وأهداف SmokePing التي قرأتها (الأهداف المرصودة) وجهاز Cacti المرتبط بكل هدف. لا يحفظ مصدر بيانات أو رسمًا أو مسار RRD أو بيانات اعتماد. قوالب الرسوم والبيانات والمضيفين في Cacti شيء مختلف وتظل بصيغة Cacti XML.

<a id="commands"></a>
## الأوامر

```sh
php plugins/smokeping/cli/smokeping.php export                         # YAML to standard output
php plugins/smokeping/cli/smokeping.php export --format=xml --file=/var/backups/smokeping-config.xml
php plugins/smokeping/cli/smokeping.php import --file=smokeping-config.yaml           # check only
php plugins/smokeping/cli/smokeping.php import --file=smokeping-config.yaml --apply --confirm=<plan>
```

ينشئ `export --file` ملفًا جديدًا لا يقرؤه إلا مالكه ويرفض الكتابة فوق ملف موجود. يقرأ `import` دون `--apply` الملف ويفحصه ويطبع كل تغيير مقترح ومعرّف خطة ولا يكتب شيئًا. يحتاج `--apply` إلى `--confirm` مع ذلك المعرّف؛ ويطبق في معاملة واحدة ويرفض إن تغير الملف أو التثبيت منذ الفحص. رمز الخروج: 0 اكتمل، 1 رُفض أو فشل، 2 أخطاء في الملف أو الخطة.

توجد الإجراءات نفسها في **Management > SmokePing Configuration** (نطاق SmokePing Admin): نزّل تصديرًا، ثم اختر ملفًا للفحص وراجع التغييرات المدرجة وطبّقها.

لا يفعل الاستيراد سوى الإضافة والتحديث. لا يحذف هدفًا، ولا ينقل ربطًا إلى جهاز آخر (أعد الربط في صفحة Inventory)، ولا ينشئ مصدر بيانات أو رسمًا أو ملف RRD، ولا يقرأ دليل بيانات SmokePing أو يكتب فيه. يكتب إلغاء التثبيت تصدير YAML للإعداد إلى **Backup Directory**، أو إلى `cache/smokeping/` في دليل Cacti إذا كان الإعداد فارغًا. للملف اسم عشوائي ووضع `0600`، ويحتوي الدليل الافتراضي على `.htaccess` يمنع كل الوصول و`index.php` لا يقدم شيئًا. إذا لم يوجد Backup Directory أو لم يكن قابلًا للكتابة، فلا يُكتب تصدير (ولا ترجع الإضافة إلى الافتراضي)، ويستمر إلغاء التثبيت ويبلّغ بمكان الملف أو بعدم كتابة ملف. اختر دليلًا خارج جذر الويب.

<a id="schema-version-1"></a>
## إصدار المخطط 1

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

الهوية. يُعرّف الهدف بـ `poller_id` و`path`. يُعرّف الجهاز بـ `hostname` و`description`؛ ويرفض الفحص الربط إذا لم يوجد جهاز بهذه الثنائية أو وجد أكثر من جهاز. يحذف الاستيراد الفراغات المحيطة بالنص لأن الإضافة تقرأ كل قيمة مخزنة بعد حذفها.

المفاتيح المحجوزة. `managed_targets` و`probe_profiles` محجوزان لإصدار لاحق يضيف الوضع المُدار. يرفضهما الإصدار 1 بخطأ يسمّي المفتاح، كي لا يُطبق ملف لذلك الإصدار جزئيًا. يوسّع إصدار لاحق المخطط بهما؛ ولا يرفع `schema_version` إلا إذا غيّر معنى مفتاح موجود هنا.

التحديثات. لهدف موجود، لا يغيّر الاستيراد إلا `host` و`probe` و`menu` و`title` و`slaves` و`nomasterpoll` و`step` و`pings` و`admin_state`. يظل إعداد SmokePing مصدر الحقول الستة الأولى؛ يعيدها الجرد التالي، لذا استخدم الاستيراد لاستعادة `admin_state` وتهيئة تثبيت جديد، لا لتحرير الأهداف.

يجب أن تكون قيم الإعدادات من النوع المذكور. لا يجوز أن يحتوي النص على محارف تحكم.

إعدادات مرجعية فقط. لا يكتب الاستيراد أبدًا إعدادًا يسمّي ملفًا أو دليلًا (كل ما تحت `reference_settings` أعلاه): يجب ألا يستطيع ملف تغيير أماكن قراءة الإضافة أو كتابتها. يحددها المخطط بنوعها، ويرفض الملف الذي يضع أحدها تحت `settings`. تحت `reference_settings` تُفحص صيغتها (مسار مطلق، أو فارغ حيث يُسمح)، وتُقارن بالتثبيت، وتُدرج في الخطة كـ `not applied, change on the Settings page` عند الاختلاف. غيّرها في صفحة Settings. تسرد ملفات 1.0.0-rc.1 المصدّرة أيضًا `smokeping_perl_binary` و`smokeping_lib_dir` هناك؛ لم تعد هذه الإعدادات موجودة ويتجاوزها الاستيراد.

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

يطابق XML مفاتيح YAML أعلاه واحدًا لواحد. القيم المنطقية `true` أو `false`. يرفض القارئ أي إعلان DOCTYPE أو كيان، ولا يحمّل موارد خارجية، ولا يقبل إلا هذه العناصر.

<a id="yaml-subset"></a>
## مجموعة YAML الفرعية

يقبل القارئ الخرائط والقوائم الكتلية، والقيم المفردة العادية والمحاطة باقتباس مفرد أو مزدوج، والتعليقات، والكتل الحرفية `|` و`|-`، مع `---` واحد اختياري في البداية. تقبل القيم مزدوجة الاقتباس تسلسلات الهروب `\\ \" \/ \n \t \r \0 \b \f \e \xHH \uHHHH`؛ وتضاعف القيم مفردة الاقتباس علامة الاقتباس (`''`). تُغلق القيمة المقتبسة في سطرها نفسه.

يرفض مع رقم السطر: المراسي (`&x`)، والأسماء المستعارة (`*x`)، والوسوم (`!x`)، والمجموعات الانسيابية (`[a, b]`، `{a: b}`)، والكتل المطوية (`>`)، والمستندات المتعددة، والتوجيهات، ومفاتيح الدمج (`<<`)، والمفاتيح المعقدة (`?`)، والمفاتيح المكررة، وعلامات الجدولة للمسافة البادئة، والقيم العادية متعددة الأسطر، والتداخل الأعمق من 8 مستويات. ويرفض قيمة عادية يقرؤها إصدار YAML آخر كغير نص: `yes` و`no` و`on` و`off` و`y` و`n`، والأعداد ذات الصفر الأولي، والكسور العشرية، والسداسي عشري، والتواريخ، والأوقات مثل `1:30`. ضعها بين اقتباسين (`'yes'`). تحتفظ `true` و`false` و`null` و`~` والأعداد الصحيحة العادية بمعناها.

يقتبس المصدّر كل سلسلة قد يسيء قارئ تحليلها، بما فيها ما يحتوي نقطتين أو `#`، أو فراغًا أوليًا أو نهائيًا، أو محرفًا أول يعامله YAML كمؤشر.

<a id="limits"></a>
## الحدود

تُحدد الملفات بـ 8 MiB و8 مستويات تداخل في الصيغتين، والسطر بـ 16 KiB. يُفحص الحجم قبل قراءة الملف.
