<!-- Source: README.md; SHA256: 17905aa1eac76a7d10dab526734605629fa3d1ada56397642ad4154c5be1feff -->

<!-- translations:start -->
[English](../../../README.md) | [简体中文](../zh-CN/README.md) | [हिन्दी](../hi-IN/README.md) | [Español (España)](../es-ES/README.md) | [Español (Latinoamérica)](../es-419/README.md) | [العربية (السعودية)](README.md) | [Afrikaans](../af-ZA/README.md) | [አማርኛ](../am-ET/README.md) | [العربية (مصر)](../ar-EG/README.md) | [العربية (المغرب)](../ar-MA/README.md) | [বাংলা](../bn-BD/README.md) | [Bosanski](../bs-BA/README.md) | [Deutsch](../de-DE/README.md) | [فارسی](../fa-IR/README.md) | [Filipino](../fil-PH/README.md) | [Français](../fr-FR/README.md) | [Français (Maroc)](../fr-MA/README.md) | [Français (Sénégal)](../fr-SN/README.md) | [Hausa](../ha-NG/README.md) | [עברית](../he-IL/README.md) | [Hrvatski](../hr-HR/README.md) | [Bahasa Indonesia](../id-ID/README.md) | [Igbo](../ig-NG/README.md) | [Italiano](../it-IT/README.md) | [日本語](../ja-JP/README.md) | [한국어](../ko-KR/README.md) | [Malagasy](../mg-MG/README.md) | [मराठी](../mr-IN/README.md) | [Bahasa Melayu](../ms-MY/README.md) | [Nederlands](../nl-NL/README.md) | [Polski](../pl-PL/README.md) | [Português (Brasil)](../pt-BR/README.md) | [Русский](../ru-RU/README.md) | [Kinyarwanda](../rw-RW/README.md) | [Soomaali](../so-SO/README.md) | [Српски](../sr-RS/README.md) | [Svenska](../sv-SE/README.md) | [Kiswahili](../sw-KE/README.md) | [தமிழ்](../ta-IN/README.md) | [తెలుగు](../te-IN/README.md) | [ไทย](../th-TH/README.md) | [Türkçe](../tr-TR/README.md) | [Українська](../uk-UA/README.md) | [اردو](../ur-PK/README.md) | [Tiếng Việt](../vi-VN/README.md) | [isiXhosa](../xh-ZA/README.md) | [Yorùbá](../yo-NG/README.md) | [繁體中文](../zh-TW/README.md) | [isiZulu](../zu-ZA/README.md)
<!-- translations:end -->

<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="../../images/logo_dark.png">
    <img src="../../images/logo.png" width="200" height="200" alt="إضافة SmokePing لـ Cacti: صبّار يرتدي شارة عليها رسم SmokePing">
  </picture>
</p>

<a id="smokeping-plugin-for-cacti"></a>
# إضافة SmokePing لـ Cacti

[![CI](https://github.com/somethingwithproof/plugin_smokeping/actions/workflows/plugin-ci-workflow.yml/badge.svg)](https://github.com/somethingwithproof/plugin_smokeping/actions/workflows/plugin-ci-workflow.yml)
[![License: GPL-2.0-or-later](https://img.shields.io/badge/license-GPL--2.0--or--later-blue.svg)](../../../LICENSE)
![PHP >= 8.2](https://img.shields.io/badge/php-%3E%3D8.2-777bb4.svg)
![Cacti >= 1.2.31](https://img.shields.io/badge/cacti-%3E%3D1.2.31-2e7d32.svg)
[![Quality Gate Status](https://sonarcloud.io/api/project_badges/measure?project=somethingwithproof_plugin_smokeping&metric=alert_status)](https://sonarcloud.io/summary/new_code?id=somethingwithproof_plugin_smokeping)
[![Coverage](https://sonarcloud.io/api/project_badges/measure?project=somethingwithproof_plugin_smokeping&metric=coverage)](https://sonarcloud.io/summary/new_code?id=somethingwithproof_plugin_smokeping)
[![Security Rating](https://sonarcloud.io/api/project_badges/measure?project=somethingwithproof_plugin_smokeping&metric=security_rating)](https://sonarcloud.io/summary/new_code?id=somethingwithproof_plugin_smokeping)
[![Reliability Rating](https://sonarcloud.io/api/project_badges/measure?project=somethingwithproof_plugin_smokeping&metric=reliability_rating)](https://sonarcloud.io/summary/new_code?id=somethingwithproof_plugin_smokeping)
![SmokePing: read-only](https://img.shields.io/badge/smokeping-read--only-6b7280.svg)

إضافة لـ Cacti 1.2 تدمج قياسات التأخير وفقدان الحزم من SmokePing كأجهزة ومصادر بيانات ورسوم عادية في Cacti. تظهر الرسوم الدخانية بجوار الجهاز المعني وتخضع لصلاحيات Cacti، ويمكن لـ Thold وMaint إصدار تنبيهات عنها وكبتها. هذا تنفيذ مستقل لـ Cacti، وليس جزءًا من [SmokePing](https://oss.oetiker.ch/smokeping/) ولا معتمدًا منه؛ ومؤلفاه الأصليان هما Tobias Oetiker وNiko Tyni. لا تضم الإضافة شيفرة SmokePing ولا تشغّلها: بل تقرأ إعداده وملفات RRD التي يكتبها، وترسم قوالبها السلاسل نفسها التي ترسمها رسوم SmokePing الأصلية.

> **الحالة: قيد التطوير. وضع المراقبة (المرحلة 1) منفّذ في `main` ومنشور كإصدار تمهيدي 1.0.0-rc.1.** تقرأ الإضافة إعداد SmokePing وملفات RRD، وتعرض حالتها، وتنشئ لها أجهزة ومصادر بيانات ورسومًا في Cacti، وتزوّد Thold وMaint بالبيانات. ولا تكتب أي شيء في SmokePing. الوضع المُدار (كتابة الإضافة لإعداد SmokePing) والمجمّعات البعيدة مرحلتان لاحقتان لم تبدآ وغير مشمولتين هنا. ما تزال التجارب في تثبيتات فعلية وتحسين مطابقة مفتاح الرسم لرسوم SmokePing ضمن الأعمال المتبقية في المعلم 4. للبدء راجع [docs/install.md](docs/install.md).

<a id="access-roles"></a>
## أدوار الوصول

| نطاق الأذونات | الوصول |
| --- | --- |
| SmokePing View | صفحات المراقبة الحالية. |
| SmokePing Operator | المراقبة وصفحة العمليات والإقرارات الشخصية أثناء التحقيق. |
| SmokePing Admin | وصول المشغّل مع الإعدادات والإجراءات الإدارية الحالية. |

بعد نسخ ملفات الترقية، افتح صفحة الإضافات بصفتك مسؤولًا لتسجيل نطاق الأذونات الجديد. امنح نطاق Operator صراحةً ضمن **الإعداد > المستخدمون** أو مجموعات المستخدمين. تحتفظ الترقيات بمعرّفات نطاقي View وAdmin ومنحهما الحالي. يرث Admin وصول Operator ويرث Operator وصول View في كل طلب؛ ولا يضيف ذلك منحًا مخزّنة للمستخدمين أو المجموعات. تتطلب إعدادات الإضافة أيضًا SmokePing Admin بالإضافة إلى إذن الإعدادات في Cacti.

تظل أذونات أجهزة Cacti تقيّد الصفوف والإجراءات المتاحة لكل مستخدم؛ وتتطلب الكائنات غير المرتبطة مسؤولًا. تبقى بيانات الاعتماد والإعدادات والربط والحذف والتغييرات الإدارية الأخرى في الصفحات الإدارية. لا تستمر الإقرارات إلا في جلسة المستخدم الحالي، لمدة ساعة على الأكثر وفقط ما دامت الملاحظة نفسها حديثة. تُبطلها الملاحظات الجديدة أو المعدّلة. ولا تجعل قياسًا فاشلًا سليمًا أو تكبح التنبيهات مطلقًا.

<a id="screenshots"></a>
## لقطات الشاشة

استيراد من شبكة SmokePing 2.8.2 عاملة إلى Cacti 1.2.31، لآخر 12 ساعة. يضم جهاز كل هدف رسمًا دخانيًا للرئيسي ورسمًا لكل تابع (`lon1`، `sgp1`)، ضمن قسم SmokePing الخاص به في شجرة الرسوم.

![شجرة Cacti لهدف Bangalore: الرسوم الدخانية للرئيسي والتابعين lon1 وsgp1](../../images/screenshot-tree-asia.png)

![شجرة Cacti لهدف London: يعرض التابع lon1 في المنطقة نفسها تأخيرًا بالميكروثانية ودخانًا مرئيًا](../../images/screenshot-tree-europe.png)

<a id="why"></a>
## الدافع

يقيس SmokePing توزيع التأخير وفقدان الحزم جيدًا؛ وتُظهر رسومه الدخانية تغيرات تخفيها رسوم المتوسطات. عند تشغيله بجوار Cacti تبقى ثلاث فجوات:

- **تحكم منفصل في الوصول.** لا يعرف CGI الخاص بـ SmokePing مستخدمي Cacti أو مجموعاته أو صلاحيات أجهزته.
- **غياب سياق الجهاز.** توجد بيانات التأخير وعدّادات واجهات الجهاز نفسه في أداتين مختلفتين.
- **غياب التكامل مع تنبيهات Cacti.** يعمل Thold وMaint على مصادر بيانات Cacti وأجهزته، لذا تُضبط تنبيهات SmokePing وتُكبت على نحو منفصل.

تسد الإضافة هذه الفجوات بتمثيل كل هدف SmokePing بكائنات Cacti تشير إلى ملفات SmokePing RRD القائمة، مع مجموعة صغيرة من قيم الملخص التي يجري استطلاعها. وهي تعيد استخدام رسوم Cacti وصلاحياته وThold وMaint. دعم مجمّعات Cacti البعيدة مخطط للمرحلة 3 وغير موجود في هذا الإصدار.

<a id="scope-and-status"></a>
## النطاق والحالة

| المرحلة | النطاق | المعالم | الحالة |
|---|---|---|---|
| 0، البحث | مصفوفة التوافق، تأكيد السلوك الأصلي، نموذج أولي لمطابقة الرسوم | [M1](https://github.com/somethingwithproof/plugin_smokeping/milestone/1) | النتائج في `docs/research`؛ بعض مسائل البحث ما تزال مفتوحة |
| 1، المراقبة | استيراد الجرد، أجهزة Cacti، الرسوم الدخانية والملخصة، تنبيهات Thold، كبت Maint، صفحة الصحة | [M2](https://github.com/somethingwithproof/plugin_smokeping/milestone/2)، [M3](https://github.com/somethingwithproof/plugin_smokeping/milestone/3)، [M4](https://github.com/somethingwithproof/plugin_smokeping/milestone/4) | منشورة كـ 1.0.0-rc.1؛ التجارب الميدانية متبقية |
| 2، الإدارة | نطاق أسماء إعداد يملكه Cacti، ملفات تعريف مجسّات ICMP وTCP، المراجعات، التحقق، إعادة التحميل، التراجع | [M5](https://github.com/somethingwithproof/plugin_smokeping/milestone/5) | مخططة، لم تبدأ |
| 3، التشغيل البعيد | SmokePing على مجمّعات Cacti البعيدة، والتابعون كمواقع قياس | [M6](https://github.com/somethingwithproof/plugin_smokeping/milestone/6) | مخططة، لم تبدأ |

خارج النطاق: محرك تنبيهات خاص، محرك تقارير، خدمة خلفية أو بروتوكول شبكة مخصص، فحوص HTTP/DNS/الخدمات المُدارة (يتولاها servcheck)، والكتابة في ملفات SmokePing RRD.

<a id="compatibility-targets"></a>
## أهداف التوافق

| المكوّن | الهدف |
|---|---|
| Cacti | 1.2.31 أو أحدث من سلسلة 1.2.x، دون ترقيعات للنواة |
| PHP | 8.2 أو أحدث؛ يظل `setup.php` قابلًا للتحليل في 8.0 كي يرفض بيئة غير مدعومة دون تعطيل واجهة Cacti |
| SmokePing | 2.9.0 و2.8.2 و2.7.3 (الأخير على Ubuntu 22.04 فقط حتى 2027-06-01) |
| Thold وMaint | لا حاجة إلى تغييرات |
| السلاسل | 10,000 لكل تثبيت مع 20 نبضة ping وخطوة 300 ثانية ([الأداء](docs/performance.md#supported-series-count)) |

<a id="documentation"></a>
## التوثيق

| | |
|---|---|
| [التثبيت](docs/install.md) | المتطلبات، ربط دليل البيانات للقراءة فقط، التثبيت، الحاويات، الإصدارات المدعومة |
| [التشغيل](docs/operations.md) | معنى كل حالة وأدلتها، فحوص الصحة، السلوك عند تعطل المدخلات |
| [إضافة الأهداف إلى Cacti](docs/provisioning.md) | هدف واحد، أجهزة عديدة، إعادة الربط، الصلاحيات |
| [التنبيهات](docs/alerting.md) | قيم الملخص، مدد Thold، التقادم والبيانات المتأخرة، التداخل مع تنبيهات SmokePing، Maint |
| [صيغة الإعداد](docs/configuration-format.md) | تصدير YAML وXML واستيرادهما |
| [النسخ الاحتياطي والاستعادة](docs/backup-restore.md) | ما يجب نسخه وكيفية استئناف العمل بعد الاستعادة |
| [الأداء](docs/performance.md) | قياسات محلية وعلى مشغّل الإصدار مع 100 و1,000 و10,000 سلسلة؛ العدد المدعوم 10,000 لكل تثبيت |
| [تأهيل الأعطال](../../failure-qualification.md) | سيناريوهات الأعطال واختبار كل منها |
| [إجراء الإصدار](../../RELEASING.md) | طلب السحب والوسم الموقّع وسلوك سير العمل والتحقق من النتيجة |
| [أرشيف الإصدار](../../release.md) | الإصدار وسجل التغييرات والأرشيف المنشور |
| [الاختبار](../../testing.md) | مجموعات الاختبار وما ينفذه CI والمجموعة الحية مع SmokePing حقيقي |

<a id="design"></a>
## التصميم

- [docs/PLAN.md](../../PLAN.md) هو الخطة الكاملة: المعمارية والحقائق الأصلية المعتمدة عليها (مع مصادرها) ونموذج الحالات وحدود الأمان وحسابات الأداء والاختبارات والمخاطر.
- يحفظ [docs/decisions/](../../decisions) سجلات القرارات المعمارية المتخذة.
- يربط [docs/REQUIREMENTS.md](../../REQUIREMENTS.md) معرّف كل متطلب بمسألته.

```mermaid
flowchart LR
    SP[SmokePing daemon] --> RRD[(RRD files)]
    RRD -->|read-only| R[Reader on the adjacent Cacti poller]
    RRD -->|read-only| G[Cacti graph templates]
    R --> SS[Script server summary data sources]
    SS -->|poller_output| T[Thold and Maint]
    G --> P[Cacti graph permissions]
```

<a id="how-work-is-tracked"></a>
## تتبّع العمل

- تتبع **المعالم** معالم الخطة. لكل منها مشكلة ملحمية تسرد مشكلاتها الفرعية وأدلة اكتمالها.
- تُصنَّف **المشكلات** بالوسوم `type: requirement` و`type: research` و`type: decision` و`type: epic`. تتضمن مشكلات المتطلبات متطلبات مرقمة قابلة للاختبار (`REQ-AREA-NNN`) ومعايير قبول.
- تشمل **الوسوم** أيضًا `area:` و`phase:` و`priority:` و`status:`. راجع [CONTRIBUTING.md](../../../CONTRIBUTING.md#labels).
- تعرض [لوحة المشروع](https://github.com/users/somethingwithproof/projects/6) كل المشكلات عبر المعالم.

<a id="contributing"></a>
## المساهمة

اقرأ [CONTRIBUTING.md](../../../CONTRIBUTING.md). تتطلب عمليات الإيداع إقرار DCO. تجري مناقشة التصميم في المشكلات؛ وتحمل القرارات المفتوحة وسم `type: decision`.

<a id="security"></a>
## الأمان

أبلغ عن الثغرات على نحو خاص؛ راجع [SECURITY.md](../../../SECURITY.md).

<a id="license"></a>
## الترخيص

رخصة GNU العمومية العامة v2.0، وهي رخصة Cacti وإضافاته نفسها. راجع [LICENSE](../../../LICENSE).
