<!-- Source: README.md; SHA256: b41ec705327127cc34ff07d1514a141262752f99c5e2296fdc323d584a777b84 -->

<!-- translations:start -->
[English](../../../README.md) | [简体中文](../zh-CN/README.md) | [हिन्दी](../hi-IN/README.md) | [Español (España)](../es-ES/README.md) | [Español (Latinoamérica)](../es-419/README.md) | [العربية (السعودية)](../ar-SA/README.md) | [Afrikaans](../af-ZA/README.md) | [አማርኛ](../am-ET/README.md) | [العربية (مصر)](../ar-EG/README.md) | [العربية (المغرب)](../ar-MA/README.md) | [বাংলা](../bn-BD/README.md) | [Bosanski](../bs-BA/README.md) | [Deutsch](../de-DE/README.md) | [فارسی](../fa-IR/README.md) | [Filipino](../fil-PH/README.md) | [Français](../fr-FR/README.md) | [Français (Maroc)](../fr-MA/README.md) | [Français (Sénégal)](../fr-SN/README.md) | [Hausa](../ha-NG/README.md) | [עברית](../he-IL/README.md) | [Hrvatski](../hr-HR/README.md) | [Bahasa Indonesia](../id-ID/README.md) | [Igbo](../ig-NG/README.md) | [Italiano](../it-IT/README.md) | [日本語](../ja-JP/README.md) | [한국어](../ko-KR/README.md) | [Malagasy](../mg-MG/README.md) | [मराठी](../mr-IN/README.md) | [Bahasa Melayu](../ms-MY/README.md) | [Nederlands](../nl-NL/README.md) | [Polski](../pl-PL/README.md) | [Português (Brasil)](../pt-BR/README.md) | [Русский](../ru-RU/README.md) | [Kinyarwanda](../rw-RW/README.md) | [Soomaali](../so-SO/README.md) | [Српски](../sr-RS/README.md) | [Svenska](../sv-SE/README.md) | [Kiswahili](../sw-KE/README.md) | [தமிழ்](../ta-IN/README.md) | [తెలుగు](../te-IN/README.md) | [ไทย](../th-TH/README.md) | [Türkçe](../tr-TR/README.md) | [Українська](../uk-UA/README.md) | [اردو](../ur-PK/README.md) | [Tiếng Việt](../vi-VN/README.md) | [isiXhosa](../xh-ZA/README.md) | [Yorùbá](README.md) | [繁體中文](../zh-TW/README.md) | [isiZulu](../zu-ZA/README.md)
<!-- translations:end -->

<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="../../images/logo_dark.png">
    <img src="../../images/logo.png" width="200" height="200" alt="Àfikún SmokePing fún Cacti: káktọ́ọ̀sì tó gbé àmì tó ní àwòrán SmokePing">
  </picture>
</p>

# Àfikún SmokePing fún Cacti

[![CI](https://github.com/somethingwithproof/plugin_smokeping/actions/workflows/plugin-ci-workflow.yml/badge.svg)](https://github.com/somethingwithproof/plugin_smokeping/actions/workflows/plugin-ci-workflow.yml)
[![License: GPL-2.0-or-later](https://img.shields.io/badge/license-GPL--2.0--or--later-blue.svg)](../../../LICENSE)
![PHP >= 8.2](https://img.shields.io/badge/php-%3E%3D8.2-777bb4.svg)
![Cacti >= 1.2.31](https://img.shields.io/badge/cacti-%3E%3D1.2.31-2e7d32.svg)
[![Quality Gate Status](https://sonarcloud.io/api/project_badges/measure?project=somethingwithproof_plugin_smokeping&metric=alert_status)](https://sonarcloud.io/summary/new_code?id=somethingwithproof_plugin_smokeping)
[![Coverage](https://sonarcloud.io/api/project_badges/measure?project=somethingwithproof_plugin_smokeping&metric=coverage)](https://sonarcloud.io/summary/new_code?id=somethingwithproof_plugin_smokeping)
[![Security Rating](https://sonarcloud.io/api/project_badges/measure?project=somethingwithproof_plugin_smokeping&metric=security_rating)](https://sonarcloud.io/summary/new_code?id=somethingwithproof_plugin_smokeping)
[![Reliability Rating](https://sonarcloud.io/api/project_badges/measure?project=somethingwithproof_plugin_smokeping&metric=reliability_rating)](https://sonarcloud.io/summary/new_code?id=somethingwithproof_plugin_smokeping)
![SmokePing: read-only](https://img.shields.io/badge/smokeping-read--only-6b7280.svg)

Àfikún Cacti 1.2 kan tí ó ń mú ìwọ̀n ìdádúró (latency) àti ìpàdánù ìpín-ìsọfúnni (loss) SmokePing wọ inú Cacti gẹ́gẹ́ bí ẹ̀rọ, orísun dátà, àti àwòrán lásán. Àwọn àwòrán èéfín (smoke graphs) á sì jókòó lẹ́gbẹ̀ẹ́ ẹ̀rọ tí wọ́n jẹ́ tiẹ̀, lábẹ́ àṣẹ Cacti, bẹ́ẹ̀ sì ni Thold àti Maint lè fi ìkìlọ̀ ránṣẹ́ lórí wọn tàbí dẹ́kun wọn. Èyí jẹ́ ìṣàgbékalẹ̀ aládàáni fún Cacti, kì í sì í ṣe apá kan tàbí ohun tí [SmokePing](https://oss.oetiker.ch/smokeping/) fọwọ́ sí, èyí tí Tobias Oetiker àti Niko Tyni jẹ́ olùpilẹ̀ṣẹ̀ rẹ̀. Àfikún náà kò kó kóòdù SmokePing sínú, bẹ́ẹ̀ ni kò ṣiṣẹ́ rẹ̀: ó kàn ń ka ìṣètò àti àwọn fáìlì RRD tí SmokePing kọ, a sì kọ́ àwọn àdàkọ àwòrán rẹ̀ láti ya àwọn ìtẹ̀lé kan náà bíi ti àwòrán SmokePing fúnra rẹ̀.

> **Ipò: ó ń lọ lọ́wọ́. A ti ṣe àgbékalẹ̀ ipò àkíyèsí (ìpele 1) lórí `main` (1.0.0-rc.1) pẹ̀lú ìyọ̀dà fún olùṣiṣẹ́, ìkójáde CSV, ìwárí RRD aláìlẹ́ni, ìtumọ̀ ìkìlọ̀ Thold, àwọn ìsopọ̀ ìṣọ̀kan ètò àyíká, àti ìṣàtúnṣe olùkójọpọ̀ òkèèrè.** Àfikún náà ń ka ìṣètò àti àwọn fáìlì RRD ti SmokePing, ó ń fi ipò wọn hàn, ó ń ṣẹ̀dá àwọn ẹ̀rọ Cacti, orísun dátà, àti àwòrán fún wọn, ó sì ń pèsè dátà fún Thold àti Maint. Kò kọ ohunkóhun sí SmokePing. Ipò ìṣàkóso (tí àfikún yóò ti kọ ìṣètò SmokePing) jẹ́ ìpele tó tẹ̀lé e, a kò tíì bẹ̀rẹ̀ rẹ̀, kò sì sí nínú ìtújáde yìí. Àwọn àyẹ̀wò lórí ìfisílẹ̀ gidi àti ìbámu tó súnmọ́ jù lọ pẹ̀lú àlàyé àmì àwòrán SmokePing ṣì ṣí sílẹ̀ nínú ìpele-àṣeyọrí 4. Wo [docs/install.md](../../install.md) láti bẹ̀rẹ̀.

## Àwọn ipa àṣẹ ìwọlé

| Ẹ̀ka Àṣẹ | Ìwọlé |
| --- | --- |
| SmokePing View | Àwọn ojú-ewé ìṣọ́wò tó wà tẹ́lẹ̀. |
| SmokePing Operator | Ìṣọ́wò pẹ̀lú ojú-ewé Operations àti ìfọwọ́sí ìṣàyẹ̀wò àdáni (triage acknowledgments). |
| SmokePing Admin | Ìwọlé Operator pẹ̀lú ìṣètò tó wà tẹ́lẹ̀ àti àwọn ìgbésẹ̀ ìṣàkóso. |

Lẹ́yìn tí o bá ti ṣe àdàkọ àwọn fáìlì ìgbéga, ṣí ojú-ewé Plugins gẹ́gẹ́ bí alámòójútó láti forúkọsílẹ̀ ẹ̀ka àṣẹ tuntun náà. Yan ẹ̀ka àṣẹ Operator ní pàtó lábẹ́ **Configuration > Users** tàbí àwọn ẹgbẹ́ olùlò. Àwọn ìgbéga ń pa àwọn ID ẹ̀ka àṣẹ View àti Admin tó wà tẹ́lẹ̀ mọ́ pẹ̀lú àwọn àṣẹ tí a ti fúnni. Ìwọlé Admin ń jogún ìwọlé Operator, ìwọlé Operator sì ń jogún ìwọlé View lórí ìbéèrè kọ̀ọ̀kan; èyí kò fi àwọn àṣẹ tí a pamọ́ kún un fún àwọn olùlò tàbí àwọn ẹgbẹ́. Àwọn ètò àfikún náà tún nílò ìwọlé SmokePing Admin ní àfikún sí àṣẹ Settings ti Cacti.

Àwọn àṣẹ ẹ̀rọ Cacti ṣì ń fi òpin sí àwọn ìlà àti ìgbésẹ̀ tó wà fún olùlò kọ̀ọ̀kan; àwọn ohun tí kò ní ìsopọ̀ nílò alámòójútó. Àwọn ìwé-ẹ̀rí, ìṣètò, ìsopọ̀, ìparẹ́ àti àwọn àtúnṣe ìṣàkóso mìíràn wà ní àwọn ojú-ewé ìṣàkóso. Àwọn ìfọwọ́sí (acknowledgments) ń wà nínú ìgbà ìṣe olùlò lọ́wọ́lọ́wọ́ nìkan, fún wákàtí kan ní pọ́ọ̀kọ́ àti níwọ̀n ìgbà tí àkíyèsí kan náà bá ṣì jẹ́ tuntun. Àwọn àkíyèsí tuntun tàbí tí a yí padà máa ń sọ wọ́n di asán. Wọn kì í fi hàn rárá pé ìwọ̀n tó kùnà wà ní ìlera tàbí kí wọ́n dẹ́kun àwọn ìkìlọ̀.

## Àwọn àwòrán ojú-ìbòjú

A kó o wọlé láti inú nẹ́tíwọ́ọ̀kì SmokePing 2.8.2 tó ń ṣiṣẹ́ lọ́wọ́ sí inú Cacti 1.2.31, fún wákàtí 12 tó kọjá. Ẹ̀rọ ìfọkànsí kọ̀ọ̀kan ní àwòrán èéfín (smoke graph) fún master àti ọ̀kan fún slave kọ̀ọ̀kan (`lon1`, `sgp1`), tí a tò sábẹ́ apá SmokePing rẹ̀ nínú igi àwòrán.

![Ìwòye igi Cacti ti ìfọkànsí Bangalore: àwọn àwòrán èéfín láti ọ̀dọ̀ master àti àwọn slave lon1 àti sgp1](../../images/screenshot-tree-asia.png)

![Ìwòye igi Cacti ti ìfọkànsí London: slave lon1 ní agbègbè kan náà fi ìdádúró microsecond hàn pẹ̀lú èéfín tó hàn gbangba](../../images/screenshot-tree-europe.png)

## Ìdí rẹ̀

SmokePing ń wọ̀n ìpínkiri ìdádúró àti ìpàdánù ìpín-ìsọfúnni dáadáa, àwọn àwòrán èéfín rẹ̀ sì ń fi ìyàtọ̀ tí àwòrán àpínkárí-ìwọ̀n nìkan máa ń fi pamọ́ hàn. Nígbà tí a bá ń lò ó lẹ́gbẹ̀ẹ́ Cacti, ó máa ń fi àlàfo mẹ́ta sílẹ̀:

- **Ìṣàkóso ìwọlé ọ̀tọ̀ọ̀tọ̀.** CGI ti SmokePing kò mọ ohunkóhun nípa àwọn olùlò Cacti, àwọn ẹgbẹ́, tàbí àwọn àṣẹ ẹ̀rọ.
- **Kò sí àyíká ẹ̀rọ.** Ìdádúró àti àwọn òǹkà ojú-ìsopọ̀ fún ẹ̀rọ kan náà wà nínú àwọn irinṣẹ́ ọ̀tọ̀ọ̀tọ̀.
- **Kò sí ọ̀nà sínú ìkìlọ̀ Cacti.** Thold àti Maint ń ṣiṣẹ́ lórí àwọn orísun dátà àti ẹ̀rọ Cacti, nítorí náà a máa ń ṣètò àti dẹ́kun àwọn ìkìlọ̀ SmokePing lọ́tọ̀.

Àfikún náà ń di àwọn àlàfo wọ̀nyí nípa ṣíṣe àpèjúwe ìfọkànsí SmokePing kọ̀ọ̀kan gẹ́gẹ́ bí àwọn ohun Cacti tó tọ́ka sí àwọn fáìlì RRD SmokePing tó wà tẹ́lẹ̀, pẹ̀lú ìwọ̀nba díẹ̀ àwọn iye àkópọ̀ tí a ṣàyẹ̀wò. Ó ń tún àwòrán yíya Cacti, àwọn àṣẹ, Thold, àti Maint lò dípò kíkọ́ àwọn ẹ̀dà ẹlẹgbẹ́ wọn. Ìṣàtúnṣe ìṣètò tí ó dá lórí poller (`replicate_out`) ń ti àwọn tábìlì ìṣètò poller òkèèrè jáde nígbà tí ó ń pa ipò agbègbè (poller 1) àti àwọn ìrántí ìgbà díẹ̀ ti àkíyèsí agbègbè olùkójọpọ̀ mọ́ ní ìyàsọ́tọ̀.

## Ààlà àti ipò iṣẹ́

| Ìpele | Ààlà | Àwọn Àṣeyọrí | Ipò |
|---|---|---|---|
| 0, ìwádìí | Àtòjọ ìbáramu, ìfìdí-múlẹ̀ ìhùwàsí upstream, àyẹ̀wò òótọ́ àwòrán | [M1](https://github.com/somethingwithproof/plugin_smokeping/milestone/1) | Àwọn àwárí wà nínú `docs/research`; díẹ̀ nínú àwọn ọ̀rọ̀ ìwádìí ṣì ṣí sílẹ̀ |
| 1, àkíyèsí | Ìkówọlé àkójọpọ̀, àwọn ẹ̀rọ Cacti, àwọn àwòrán èéfín àti àkópọ̀, ìkìlọ̀ Thold àti ìtumọ̀ ìkìlọ̀, ìdẹ́kun Maint, àwọn ìwòye tí a yọ̀, ìkójáde CSV, ìwárí RRD aláìlẹ́ni, àwọn ìsopọ̀ ètò àyíká | [M2](https://github.com/somethingwithproof/plugin_smokeping/milestone/2), [M3](https://github.com/somethingwithproof/plugin_smokeping/milestone/3), [M4](https://github.com/somethingwithproof/plugin_smokeping/milestone/4) | A ti ṣe àgbékalẹ̀ rẹ̀; àwọn àyẹ̀wò ṣì ṣí sílẹ̀ |
| 2, ìṣàkóso | Ààyè-orúkọ ìṣètò tí Cacti ni, àwọn profaili ìwádìí ICMP àti TCP, àwọn àtúnyẹ̀wò, ìfìdí-múlẹ̀, ìtúnṣiṣẹ́, ìpadà-sẹ́yìn | [M5](https://github.com/somethingwithproof/plugin_smokeping/milestone/5) | A ti ṣètò rẹ̀, kò tíì bẹ̀rẹ̀ |
| 3, òkèèrè | Àwọn ìbéèrè tí ó dá lórí poller àti ìṣàtúnṣe tábìlì `replicate_out` fún àwọn olùkójọpọ̀ dátà òkèèrè (`RemoteReplication`); ìjẹ́rìísí ìgbékalẹ̀ SmokePing olùkójọpọ̀ púpọ̀ ní kíkún | [M6](https://github.com/somethingwithproof/plugin_smokeping/milestone/6) | A ti ṣe àgbékalẹ̀ ìṣàtúnṣe àti ààlà poller; ìjẹ́rìísí ìgbékalẹ̀ olùkójọpọ̀ púpọ̀ ṣì ṣí sílẹ̀ |

Àwọn ìṣàn-iṣẹ́ olùṣiṣẹ́ tí a ṣe àgbékalẹ̀ rẹ̀, àwọn àṣẹ CLI, àti àwọn ìsopọ̀ ètò àyíká:

- **Àkójọpọ̀ ohun-ìní àti ìyọ̀dà ìfọkànsí (`smokeping_inventory.php`, `smokeping.php`)**: `TargetFilter`, `TargetRepository::filteredForPoller()`, `InventoryPage`, àti `ViewPage` pèsè wíwá (`filter`), ìwádìí (`probe`), ìwà nínú àkójọpọ̀ (`presence`), ipò ìsopọ̀ (`mapping`), ipò títuntun (`state`), àti ìpín ojú-ewé àṣàyàn (`rows`: `All`, `25`, `50`, `100`, `200`). A máa ń mú ìríran ẹ̀rọ ṣẹ nínú SQL ṣáájú kíkà kí àpapọ̀ má baà fi àwọn ẹ̀rọ tí a fi pamọ́ hàn rárá.
- **Ìkójáde CSV (`smokeping.php?action=export_csv`)**: `CsvExporter` ń ṣàn àbájáde RFC 4180 CRLF jáde fún ìwòye tí a yọ̀ tí ó ń ṣiṣẹ́ lábẹ́ àwọn àṣẹ ẹ̀rọ olùpè, ó sì ń fi àmì àpọ́sítírọ́fì síwájú àwọn sẹ́ẹ̀lì tí ó bẹ̀rẹ̀ pẹ̀lú `=`, `+`, `-`, `@`, tab, tàbí carriage return láti dẹ́kun àwọn ìlànà ìṣirò ìwé-ìṣirò.
- **Ìwárí RRD aláìlẹ́ni (`smokeping_health.php`)**: `OrphanRrdScanner` àti `HealthCheck::orphanRrds()` ń ṣe àyẹ̀wò tó ní ààlà lórí ibi ìsopọ̀ SmokePing lórí poller olùkà, wọ́n ń fò `__cgi`, `__sortercache`, àti àwọn ìjápọ̀ àmì kọjá, wọ́n sì ń fi àwọn fáìlì `.rrd` aláìlẹ́ni wé àwọn àbá ìtún-so tó ṣí sílẹ̀.
- **Ìyípadà SmokePing sí Thold (`smokeping_health.php`, `cli/smokeping.php`)**: `TholdTranslator` àti `TholdGenerator` ń ka àwọn ìtumọ̀ `*** Alerts ***` ti SmokePing wọ́n sì ń túmọ̀ àwọn ìlànà ìpàdánù títẹ̀léra tí a ṣe àtìlẹ́yìn fún sí àwọn ààlà `loss_pct` Thold tí ó dọ́gba láìyí àwọn fáìlì ìṣètò SmokePing padà. Ìṣàn wẹ́ẹ̀bù ń lo àyẹ̀wò ṣáájú GET (`action=thold_preview`, `HealthPage::renderTholdPreview()`) tí POST tí CSRF dáàbò bò (`action=thold_create`) tẹ̀ lé e. A máa ń fò àwọn ìlànà tí a kò ṣe àtìlẹ́yìn fún (àwọn ìkìlọ̀ RTT, ìdínkù wildcard, àwọn ìpele àdàlú) kọjá láìkọ nǹkan kan.
- **CLI Alámòójútó (`cli/smokeping.php`)**: ṣe àtìlẹ́yìn fún `provision <target path> [--device=ID] [--location=NAME]`, `relink <old target path> <new target path> [--location=NAME]`, `thold-generate <target path> [--alert=NAME] [--apply]`, `size-graphs`, `upgrade-graphs`, `export [--format=yaml|xml] [--file=PATH]`, `import --file=PATH [--format=yaml|xml] [--apply --confirm=PLAN]`, àti `import-smokeping [--dry-run | --apply --confirm=PLAN] [--file=PATH] [--sections=A,B/C] [--device-per=host|target] [--device-template=ID] [--poller=ID] [--site=ID] [--tree=ID | --tree-name=NAME]`.
- **Àwọn ìsopọ̀ ètò àyíká (`setup.php`, `Schema::HOOKS`)**: forúkọ sílẹ̀ àwọn ìsopọ̀ 21, pẹ̀lú `abilities_register` (`AbilitiesProvider`: `smokeping-list-targets` àti `smokeping-get-health` fún kíkà nìkan, tí a ṣàkóso pẹ̀lú agbègbè View lòdì sí ID olùlò olùpè, láìdá àṣírí kankan padà), `doctor_register_checks` (`DoctorChecks`: `smokeping_stale_targets`, `smokeping_freshness_breaches`, `smokeping_orphan_rrds`, `smokeping_config_path`), `casc_register_providers` (`CascProvider`: `smokeping` YAML/XML `export`, `validate`, `plan`, àti `apply` tí `ConfigCodec` àti `ConfigService` ń ṣe àtìlẹ́yìn fún), àti `replicate_out` (`RemoteReplication`: ìṣọ̀kan tí ó dá lórí poller ti `plugin_smokeping_target`, `plugin_smokeping_mapping`, `plugin_smokeping_object`, àti `plugin_smokeping_relink` fún àwọn poller òkèèrè tí ó ní `poller_id > 1`).

Àwọn ohun tí kò sí ní ààlà: ẹ̀rọ ìkìlọ̀ tí àfikún ni, ẹ̀rọ ìjábọ̀, daemon àdáni tàbí ìlànà nẹ́tíwọ́ọ̀kì, àwọn àyẹ̀wò HTTP/DNS/iṣẹ́ tí a ṣàkóso (servcheck ń bo àwọn wọ̀nyẹn), àti kíkọ sínú àwọn fáìlì RRD ti SmokePing.

## Àwọn àfojúsùn ìbáramu

| Ẹ̀yà | Àfojúsùn |
|---|---|
| Cacti | 1.2.31 tàbí tuntun jù bẹ́ẹ̀ lọ nínú 1.2.x, kò sí àtúnṣe pàtàkì |
| PHP | 8.2 tàbí tuntun jù bẹ́ẹ̀ lọ; `setup.php` máa ń ṣe é kà lórí 8.0 kí àyíká ìṣiṣẹ́ tí kò ní ìtìlẹ́yìn lè gba ìkọ̀sílẹ̀ dípò UI Cacti tó bàjẹ́ |
| SmokePing | 2.9.0, 2.8.2, àti 2.7.3 (Ubuntu 22.04 nìkan, títí di 2027-06-01) |
| Thold, Maint | Kò nílò àyípadà kankan |
| Àwọn ìtẹ̀lé | 10,000 fún ìfisílẹ̀ kọ̀ọ̀kan ní ìfi-ránsẹ́ 20 àti ìgbésẹ̀ 300 s ([iṣẹ́ àti iyára](../../performance.md#supported-series-count)) |

## Ìwé àkọsílẹ̀

| | |
|---|---|
| [Ìfisílẹ̀](../../install.md) | Àwọn ohun tí a nílò, ìsopọ̀ kíka-nìkan ti àpò dátà SmokePing, ìfisílẹ̀, àwọn kóńtéénà, àwọn ẹ̀dà tó ní ìtìlẹ́yìn |
| [Ìṣiṣẹ́](../../operations.md) | Ohun tí ipò kọ̀ọ̀kan túmọ̀ sí àti ẹ̀rí rẹ̀, àwọn àyẹ̀wò ìlera, ìhùwàsí nígbà tí àwọn ìtẹ̀wọlé bá kùnà |
| [Fi àwọn ìfọkànsí kún Cacti](../../provisioning.md) | Ìfọkànsí kan, ọ̀pọ̀ ẹ̀rọ, títún-so, àwọn àṣẹ |
| [Ìkìlọ̀](../../alerting.md) | Àwọn iye àkópọ̀, àkókò Thold, ìpẹ́jù dátà, dátà tó pẹ́ dé, ìsopọ̀ pẹ̀lú àwọn ìkìlọ̀ SmokePing, Maint |
| [Ìlànà ìṣètò](../../configuration-format.md) | Ìkówọlé àti ìkójáde YAML àti XML |
| [Ìpamọ́ àti ìdápadà](../../backup-restore.md) | Ohun tí a ó pamọ́ àti bí ìdápadà ṣe ń tẹ̀síwájú |
| [Iṣẹ́ àti iyára](../../performance.md) | Àwọn ìwọ̀n agbègbè àti ti olùṣiṣẹ́ ìtújáde ní 100, 1,000, àti 10,000 ìtẹ̀lé, àti iye ìtẹ̀lé tó ní ìtìlẹ́yìn: 10,000 fún ìfisílẹ̀ kọ̀ọ̀kan |
| [Ìdánilójú ìkùnà](../../failure-qualification.md) | Àwọn ìṣẹ̀lẹ̀ ìkùnà àti bí a ṣe ń dán ọ̀kọ̀ọ̀kan wò |
| [Ìtújáde](../../RELEASING.md) | Ìlànà ìtújáde: ìbéèrè ìfàsẹ́yìn (pull request), àmì tí a fọwọ́ sí, ohun tí iṣẹ́ ìṣàkóso ń ṣe, bí a ṣe ń fìdí èsì múlẹ̀ |
| [Àkójọ ìtújáde](../../release.md) | Ẹ̀dà, àkọsílẹ̀ àtúnṣe, àti àkójọ ìtújáde |
| [Ìdánwò](../../testing.md) | Àwọn àkójọ ìdánwò, ohun tí CI ń ṣe, àti ìdánwò láàyè lòdì sí SmokePing gidi |

## Àpẹẹrẹ ètò

- [docs/PLAN.md](../../PLAN.md) ni ètò kíkún: àpẹẹrẹ ètò, àwọn òtítọ́ upstream tí ó gbẹ́kẹ̀ lé (pẹ̀lú àwọn orísun), àpẹẹrẹ ipò, ààlà ààbò, ìṣirò iṣẹ́ àti iyára, ìdánwò, àti àwọn ewu.
- [docs/decisions/](../../decisions) ní àwọn àkọsílẹ̀ ìpinnu àpẹẹrẹ ètò fún àwọn yíyàn tí a ti ṣe tẹ́lẹ̀.
- [docs/REQUIREMENTS.md](../../REQUIREMENTS.md) ń so ID ohun-ìbéèrè kọ̀ọ̀kan pọ̀ mọ́ ọ̀rọ̀ rẹ̀.
- Ìfìdí-múlẹ̀ agbègbè ń ṣiṣẹ́ àwọn àyẹ̀wò ẹyọ Pest àti ìlànà orísun (`pest --configuration=phpunit.xml`), PHPStan Ipele 9 (`CACTI_PATH=<cacti-dir> phpstan analyse -c phpstan.neon --no-progress`), àwọn àyẹ̀wò gírámà ojú-ìbẹ̀rẹ̀ (`php -l` lórí `*.php`, `include/*.php`, `scripts/*.php`, `cli/*.php`), àti àwọn àyẹ̀wò ìtumọ̀ (`sh locales/build_gettext.sh --check`). Wo [docs/testing.md](../../testing.md) fún àwọn àkójọ ìdánwò ìṣọ̀kan àti ìparí-sí-ìparí nínú kóńtéénà.

```mermaid
flowchart LR
    SP["Daemon SmokePing"] --> RRD[("Àwọn fáìlì RRD")]
    RRD -->|kíka-nìkan| R["Òǹkà lórí olùṣàyẹ̀wò Cacti tó súnmọ́"]
    RRD -->|kíka-nìkan| G["Àwọn àdàkọ àwòrán Cacti"]
    R --> SS["Àwọn orísun dátà àkópọ̀ script server"]
    SS -->|poller_output| T["Thold àti Maint"]
    G --> P["Àwọn àṣẹ àwòrán Cacti"]
```

## Bí a ṣe ń tọ́pa iṣẹ́

- **Àwọn àṣeyọrí** tẹ̀lé àwọn àṣeyọrí ètò náà. Ọ̀kọ̀ọ̀kan ní ọ̀rọ̀ àkójọpọ̀ (epic issue) tó ṣe àkójọ àwọn ọ̀rọ̀ abẹ́ rẹ̀ àti ẹ̀rí ìparí.
- **Àwọn ọ̀rọ̀** ni a pín sí ìsọ̀rí nípa àmì: `type: requirement`, `type: research`, `type: decision`, `type: epic`. Àwọn ọ̀rọ̀ ohun-ìbéèrè ń sọ àwọn ohun-ìbéèrè tí a nọ́mbà tí a sì lè dán wò (`REQ-AREA-NNN`) àti àwọn ìlànà ìtẹ́wọ́gbà.
- **Àwọn àmì** tún gbé `area:`, `phase:`, `priority:`, àti `status:`. Wo [CONTRIBUTING.md](../../../CONTRIBUTING.md#labels).
- [Pátákó iṣẹ́](https://github.com/users/somethingwithproof/projects/6) ń fi gbogbo àwọn ọ̀rọ̀ hàn káàkiri àwọn àṣeyọrí.

## Ìkópa

Ka [CONTRIBUTING.md](../../../CONTRIBUTING.md). Àwọn ìfìpamọ́ nílò ìfọwọ́sowọ́pọ̀ DCO. Ìjíròrò àpẹẹrẹ ètò máa ń wáyé lórí àwọn ọ̀rọ̀; àwọn ìpinnu tó ṣí sílẹ̀ gbé àmì `type: decision`.

## Ààbò

Jábọ̀ àwọn àléébù ní ìkọ̀kọ̀; wo [SECURITY.md](../../../SECURITY.md).

## Ìwé-àṣẹ

GNU General Public License v2.0, ìwé-àṣẹ kan náà pẹ̀lú Cacti àti àwọn àfikún rẹ̀. Wo [LICENSE](../../../LICENSE).
