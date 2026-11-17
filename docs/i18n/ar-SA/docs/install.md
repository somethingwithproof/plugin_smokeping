<!-- Source: docs/install.md; SHA256: 0d075ea04065badbbe1a97434609b979a10cb760b6f483e6cb7f8bc4768a214e -->

<a id="installing-the-smokeping-plugin"></a>
# تثبيت إضافة SmokePing

ينقل الدليل مضيف Cacti 1.2 وتثبيت SmokePing من البداية إلى الرسوم في Cacti. يغطي تثبيت الإضافة والوصول للقراءة فقط إلى دليل البيانات (أكثر الأجزاء عرضة للخطأ)، والتحقق والحاويات والدعم. العمل اليومي في [التشغيل](operations.md)، والتنبيهات في [التنبيهات](alerting.md)، والنسخ في [النسخ والاستعادة](backup-restore.md).

<a id="what-is-supported"></a>
## ما هو مدعوم

| المكوّن | المدعوم | المختبَر هنا |
|---|---|---|
| Cacti | 1.2.31 وفرع 1.2.x الحالي (يغير 1.2.32 فحص مسارات المصادر؛ راجع الربط) | إصدار 1.2.31 ونسخة تطوير 1.2.32 |
| PHP | 8.2 أو أحدث. يُرفض التثبيت على 8.0 و8.1 ويواصل Cacti العمل | 8.2.35 للتكامل والاختبارات الشاملة؛ فحص صياغة نقاط الدخول على 8.0 |
| SmokePing | 2.9.0 و2.8.2 و2.7.3 (Ubuntu 22.04 فقط حتى 2027-06-01) | الثلاثة لمحلل الإعداد، بالمقارنة مع الأصلي في 2.7.3 (Ubuntu 22.04) و2.8.2 (Debian 13) و2.9.0 (Fedora 45)؛ والثلاثة لدقة الرسوم |
| rrdtool | إصدار Cacti؛ يشغّل القارئ `path_rrdtool` | 1.7.2 |
| Thold وMaint | دون تغيير | نسخ مساحة عمل الإضافات |
| عدد السلاسل | 10,000 لكل تثبيت، 20 نبضة وخطوة 300 ثانية، أربع vCPU مخصصة و8 GB ([الأداء](performance.md#supported-series-count)) | 100 و1,000 و10,000 على مشغّل الإصدار |

«السلسلة» هدف في موقع قياس واحد (الرئيسي أو تابع). يتبع دعم التوزيعات فترات الدعم الكامل للمورّدين؛ راجع [المصفوفة](../../../research/distribution-matrix/README.md). توفر Debian 13 وUbuntu 22.04 إلى 26.10 وEL 9 وFedora 43 إلى 45 وAlpine 3.22 إلى 3.24 حزم SmokePing. **لا توجد حزمة SmokePing في EL 10 وopenSUSE Leap 16**؛ تعمل الإضافة هناك بقدر عمل تثبيت SmokePing من المصدر، وهو خارج هذا الدليل.

<a id="how-the-pieces-fit"></a>
## كيف تتكامل الأجزاء

يكتب SmokePing ملفات RRD، ويرسمها Cacti وتقرأ الإضافة أحدث قيمها، ولا يكتبها أي منهما. يرفض Cacti 1.2.32 والأحدث مسار مصدر خارج RRA، لذا تشير الإضافة إلى دليل SmokePing عبر **ربط للقراءة فقط داخل مسار RRA في Cacti**، افتراضيًا `<rra_path>/smokeping` ([القرار 0007](../../../decisions/0007-read-only-mount-under-rra-path.md)). الربط هو الحماية الرئيسية أيضًا: لا يستطيع تنظيف Cacti الحذف أو إعادة التسمية عبره.

يجب أن يقرأ مجمّع Cacti الذي يشغّل القارئ شيئين على مضيف SmokePing:

1. الإعداد (`/etc/smokeping/config`) وملفاته المضمّنة، بمحلل PHP الخاص بالإضافة. يجب أن تبقى المضمّنة في دليل الإعداد أو تحته؛ تُرفض غيرها. لا تشغّل الإضافة Perl ولا تحتاج مكتبته الخاصة بـ SmokePing.
2. دليل البيانات عبر الربط.

عند اختلاف المضيفين، شارك البيانات للقراءة فقط (NFS) واربطها على مضيف مجمّع SmokePing؛ راجع [التشغيل](operations.md#topologies). SmokePing على مجمّع Cacti بعيد مرحلة لاحقة غير مدعومة هنا.

<a id="1-install-smokeping-if-it-is-not-installed"></a>
## 1. تثبيت SmokePing (إن لم يكن مثبتًا)

على Debian 13 وUbuntu:

<!-- verify:install-smokeping-deb -->
```sh
apt-get update
DEBIAN_FRONTEND=noninteractive apt-get install -y smokeping
```

على EL 9 (الحزمة في EPEL وتحتاج CodeReady Builder):

<!-- verify:install-smokeping-el -->
```sh
dnf install -y epel-release
dnf config-manager --set-enabled crb
dnf install -y smokeping
```

اضبط SmokePing كالمعتاد وشغّله بـ `systemctl enable --now smokeping`. دليل البيانات `/var/lib/smokeping` على Debian وUbuntu وAlpine، و`/var/lib/smokeping/rrd` على EL وFedora. أدناه `DATADIR` هو ذلك الدليل (`datadir` في `*** Database ***`)، و`RRA` مسار Cacti (Console > Configuration > Settings > Paths، «RRA Path»؛ `/var/www/html/cacti/rra` في الأمثلة).

**محتويات أخرى للدليل.** تحفظ حزمة Debian مخابئ التابعين في `DATADIR/__cgi/<section>/` (قيمة `dyndir`) ولا تنشئ أدلة الأقسام بنفسها، لذا يترك تابع لم يبلّغ بعد ذلك الدليل فارغًا أو مفقودًا. يحفظ Alpine مخبأ الصور في `DATADIR/images`، ومخابئ الفرز في `DATADIR/__sortercache`. تتجاهل الإضافة الجميع: لا تسرد دليل البيانات قط، وتقرأ فقط RRD المذكورة في الإعداد (`<section>/<target>.rrd` و`<target>~<slave>.rrd` لكل تابع). فلا تُقرأ هذه الأدلة أو RRD بلا هدف مالك (مثل `Local/LocalMachine.rrd`) ولا تُسرد ولا تُبلّغ كخطأ. تحقق ذلك على رئيسي Debian 13 وتابعين ([الاختبار](../../../testing.md#the-live-suite)).

<a id="2-let-the-cacti-poller-read-smokeping"></a>
## 2. السماح لمجمّع Cacti بقراءة SmokePing

في التوزيعات ذات الحزم، RRD مقروءة للجميع وكل الأدلة فوقها قابلة للاجتياز للجميع، فلا يحتاج مستخدم المجمّع **عضوية مجموعة**. الإعداد مقروء للجميع على Debian وUbuntu وAlpine، و`0640 root:apache` على EL وFedora حيث لا يقرؤه إلا `apache` وroot.

افحص القراءة كمستخدم المجمّع (`www-data` على Debian/Ubuntu، و`apache` على EL/Fedora):

<!-- verify:poller-access -->
```sh
runuser -u "$POLLER_USER" -- test -r "$DATADIR" && echo "datadir readable"
runuser -u "$POLLER_USER" -- test -r /etc/smokeping/config && echo "config readable"
runuser -u "$POLLER_USER" -- ls "$DATADIR" > /dev/null && echo "datadir listable"
```

يجب أن يطبع كل سطر، وهذا ما يحدث مع صلاحيات الحزم؛ لا حاجة لمجموعة أو إعداد آخر على مضيف SmokePing. يفشل فقط مع صلاحيات أشد (مثل `datadir` بوضع `0750 smokeping:smokeping`) أو مستخدم غير `apache` على EL/Fedora حيث الإعداد `0640 root:apache`. امنح ذلك المستخدم وحده القراءة، مثل ACL (`setfacl -R -m u:$POLLER_USER:rX` على دليل الإعداد و`DATADIR`)، ولا تجعل ملفات SmokePing قابلة للكتابة لمستخدم Cacti.

<a id="3-mount-the-data-directory-read-only-inside-cactis-rra-path"></a>
## 3. ربط دليل البيانات للقراءة فقط داخل RRA

اختر إحدى الطريقتين. تنشئان ربطًا محليًا يحتاج root مرة واحدة.

<a id="option-a-an-etcfstab-line"></a>
### الخيار A: سطر `/etc/fstab`

<!-- verify:mount-fstab -->
```sh
mkdir -p "$RRA/smokeping"
echo "$DATADIR  $RRA/smokeping  none  bind,ro  0 0" >> /etc/fstab
mount "$RRA/smokeping"
```

يسري فورًا وعند كل إقلاع. إن اختبرت بـ `mount --bind` فأضف إعادة ربط لأن `util-linux` القديم يتجاهل `ro` في الربط الأول: `mount -o remount,bind,ro "$RRA/smokeping"`.

<a id="option-b-a-systemd-mount-unit"></a>
### الخيار B: وحدة systemd `.mount`

يجب أن يكون اسم الملف مسار نقطة الربط مع تحويل `/` إلى `-`؛ دع systemd يحسبه:

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

استخدم `RequiresMountsFor=` في وحدات الخدمات المعتمدة على الربط إن أمكن أن تبدأ قبله، مثل `RequiresMountsFor=/var/www/html/cacti/rra/smokeping` في إعداد ملحق لـ `php-fpm.service`.

لـ SmokePing على مضيف آخر، استبدل الربط بـ NFS للقراءة فقط (`smokeping-host:/var/lib/smokeping  $RRA/smokeping  nfs  ro,nfsvers=4.2  0 0`)، وصدّر الدليل للقراءة فقط من ذلك المضيف.

<a id="verify-the-mount"></a>
### التحقق من الربط

<!-- verify:mount-verify -->
```sh
findmnt -no TARGET,SOURCE,OPTIONS "$RRA/smokeping"
findmnt -no OPTIONS "$RRA/smokeping" | tr ',' '\n' | grep -x ro
touch "$RRA/smokeping/.cacti-write-test" && echo "WRITABLE: fix the mount before continuing" || echo "read-only, as required"
ls "$RRA/smokeping" | head
```

يجب ظهور `ro` وفشل `touch` برسالة `Read-only file system` (حتى كـ root)، وأن تعرض القائمة أقسام SmokePing. تعيد Health الفحص من `/proc/self/mountinfo` وتعد الربط القابل للكتابة أو المفقود أو الرابط الرمزي في المسار **خطأً**. الرابط الرمزي ليس بديلًا: يرفض Cacti 1.2.32 والأحدث مسارات تمر به.

<a id="4-install-the-plugin"></a>
## 4. تثبيت الإضافة

من أرشيف إصدار (راجع [التغليف](../../../release.md)):

```sh
tar -xzf smokeping-0.2.0.tar.gz -C /var/www/html/cacti/plugins
chown -R root:root /var/www/html/cacti/plugins/smokeping
cd /var/www/html/cacti
sudo -u www-data php cli/plugin_manage.php --plugin=smokeping --install --enable --allperms
```

يجب تسمية الدليل `smokeping`. شغّل الأمر الأخير كمستخدم يملك ملفات Cacti (`apache` على EL). يمنح `--allperms` مسؤول وحدة التحكم النطاقات الثلاثة؛ يحصل الآخرون على **SmokePing View** أو **SmokePing Operator** أو **SmokePing Admin** عبر User Management. يشمل Operator وصول View ويشمل Admin وصول Operator. تثبّت الإضافة الجداول وقوالب الرسوم والمضيفين؛ عند فشل استيراد القوالب تذكر ذلك في Health وتواصل العمل، ويعيد **Retry Template Import** المحاولة بعد إصلاح السبب.

يُرفض التثبيت على PHP أقدم من 8.2 برسالة تحدد المطلوب، ولا يتأثر باقي Cacti.

<a id="5-configure-and-confirm"></a>
## 5. الإعداد والتأكيد

افتح **Console > Configuration > Settings > SmokePing** وافحص:

| الإعداد | المعنى |
|---|---|
| SmokePing Poller | مجمّع Cacti على مضيف SmokePing أو يشاركه نظام الملفات. هو وحده يقرأ البيانات. |
| SmokePing Configuration File | `/etc/smokeping/config` ما لم تنقله |
| SmokePing Data Directory | `DATADIR` كما يراه SmokePing |
| Cacti Mount of the Data Directory | نقطة الخطوة 3؛ الافتراضي `<rra_path>/smokeping` |
| Loss Colors | Automatic، إلا إذا تعذر على القارئ رؤية `Smokeping.pm` وكان الإصدار 2.7: اختر SmokePing 2.7. راجع [الألوان](provisioning.md#loss-colors) |

افتح **SmokePing Health** على خادم المجمّع. يقرأ صف الإعداد الملف فورًا ويعرض عدد الأهداف أو أول خطأ بملفه وسطره. بعد الدورة التالية يجب أن تكون كل الصفوف OK: PHP والإعداد والقارئ والجرد والربط والقوالب. لتشغيل الجرد والقارئ فورًا:

```sh
sudo -u www-data php plugins/smokeping/poller_smokeping.php --force --inventory
```

يسرد **SmokePing Inventory** الآن جميع الأهداف. لا رسوم بعد؛ راجع [التجهيز](provisioning.md) لإضافتها إلى Cacti.

<a id="languages"></a>
## اللغات

تضم الإضافة الإسبانية لإسبانيا (`es-ES`) والفرنسية (`fr-FR`) والألمانية (`de-DE`) والصينية المبسطة (`zh-CN`) والهندية (`hi-IN`) والعربية (`ar-SA`) واليابانية (`ja-JP`). يعرضها Cacti بعد تفعيل Language Support (Configuration > Settings > General > Language Support) واختيار المستخدم اللغة. تضم أيضًا إسبانية أمريكا اللاتينية (`es-419`)، لكن Cacti 1.2 لا يوفر هذه المحلية للاختيار؛ لن تعمل حتى يضيفها Cacti، وإلى ذلك الحين اختر الإسبانية (`es-ES`).

<a id="containers"></a>
## الحاويات

شغّل القارئ حيث يرى العنصرين في أول الدليل.

- **Cacti في حاوية وSmokePing على المضيف.** اربط البيانات للقراءة فقط عند RRA والإعداد أيضًا للقراءة فقط:

  ```sh
  docker run -d --name cacti \
    -v /var/lib/smokeping:/var/www/html/cacti/rra/smokeping:ro \
    -v /etc/smokeping:/etc/smokeping:ro \
    my-cacti-image
  ```

  استبدل الجانب الأيسر لربط البيانات بـ `DATADIR` للمضيف. لا تحتاج الصورة شيئًا من SmokePing، لا Perl ولا حزمة `smokeping`. يجب أن يكون PHP 8.2 أو أحدث. تفحص Health `:ro`؛ غيابه خطأ.
- **Docker Compose.** نفسه كمدخلات `volumes:` مع `read_only: true` و`type: bind`:

  ```yaml
  services:
    cacti:
      volumes:
        - type: bind
          source: /var/lib/smokeping
          target: /var/www/html/cacti/rra/smokeping
          read_only: true
  ```

- **Kubernetes.** وحدة تخزين مع `readOnly: true` في `volumeMounts`، خلفها hostPath أو NFS أو CSI يكتب فيه pod SmokePing.
- **SmokePing في حاوية.** ضع `datadir` على وحدة مشتركة مع مضيف Cacti واربطها للقراءة فقط كما سبق. لا تحتاج الإضافة الدخول إلى حاوية SmokePing.

<a id="cactis-rrd-cleaner-will-list-smokeping-files"></a>
## منظف RRD في Cacti يسرد ملفات SmokePing

يمر المنظف (**Utilities > RRD Cleaner**) بكل RRA، فيسرد RRD الخاصة بـ SmokePing بلا مصدر Cacti، ومنها `target~slave.rrd` للتابع، كغير مستخدمة ويعرض حذفها. لا تحددها. يفشل الحذف أو الأرشفة عبر الربط للقراءة فقط، ويسجل Cacti تحذيرًا ويحذف مدخل الطابور، فتبقى الملفات، لكن التحذيرات ضوضاء وقد يفقد الربط القابل للكتابة البيانات. تزيل الإضافة المداخل التي تتعرف عليها قبل التطهير (تمسح الطابور أول كل دورة)، فتقل التحذيرات. لا قائمة استثناء للمنظف، فاقرأ قائمته قبل التحديد.

<a id="upgrading-and-removing"></a>
## الترقية والإزالة

استبدل دليل الإضافة بالإصدار الجديد وافتح Plugins أو انتظر الدورة التالية؛ ترحّل الجداول والقوالب ولا تسجل الإصدار إلا بعد نجاح كل خطوة، فتُستكمل الترقية المنقطعة في التالية. يوقف تعطيل الإضافة مهامها ويترك ملفات SmokePing. **قبل إلغاء التثبيت، صدّر الإعداد بنفسك وتحقق من أن النسخة الاحتياطية قابلة للقراءة وكاملة.** يحاول إلغاء التثبيت تصدير إعداد الإضافة بصيغة YAML إلى Backup Directory أو `cache/smokeping/` في Cacti (اسم عشوائي، وضع `0600`، والمسار معروض عند النجاح). إذا فشل التصدير، يُسجّل تحذير ويُعرض، لكن إلغاء التثبيت يستمر. تُحذف جداول الإضافة ما لم يُضبط **Keep Plugin Tables on Uninstall**، ولا تُمس ملفات SmokePing أو إعداداته مطلقًا.
