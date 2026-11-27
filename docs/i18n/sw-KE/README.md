<!-- Source: README.md; SHA256: b41ec705327127cc34ff07d1514a141262752f99c5e2296fdc323d584a777b84 -->

<!-- translations:start -->
[English](../../../README.md) | [简体中文](../zh-CN/README.md) | [हिन्दी](../hi-IN/README.md) | [Español (España)](../es-ES/README.md) | [Español (Latinoamérica)](../es-419/README.md) | [العربية (السعودية)](../ar-SA/README.md) | [Afrikaans](../af-ZA/README.md) | [አማርኛ](../am-ET/README.md) | [العربية (مصر)](../ar-EG/README.md) | [العربية (المغرب)](../ar-MA/README.md) | [বাংলা](../bn-BD/README.md) | [Bosanski](../bs-BA/README.md) | [Deutsch](../de-DE/README.md) | [فارسی](../fa-IR/README.md) | [Filipino](../fil-PH/README.md) | [Français](../fr-FR/README.md) | [Français (Maroc)](../fr-MA/README.md) | [Français (Sénégal)](../fr-SN/README.md) | [Hausa](../ha-NG/README.md) | [עברית](../he-IL/README.md) | [Hrvatski](../hr-HR/README.md) | [Bahasa Indonesia](../id-ID/README.md) | [Igbo](../ig-NG/README.md) | [Italiano](../it-IT/README.md) | [日本語](../ja-JP/README.md) | [한국어](../ko-KR/README.md) | [Malagasy](../mg-MG/README.md) | [मराठी](../mr-IN/README.md) | [Bahasa Melayu](../ms-MY/README.md) | [Nederlands](../nl-NL/README.md) | [Polski](../pl-PL/README.md) | [Português (Brasil)](../pt-BR/README.md) | [Русский](../ru-RU/README.md) | [Kinyarwanda](../rw-RW/README.md) | [Soomaali](../so-SO/README.md) | [Српски](../sr-RS/README.md) | [Svenska](../sv-SE/README.md) | [Kiswahili](README.md) | [தமிழ்](../ta-IN/README.md) | [తెలుగు](../te-IN/README.md) | [ไทย](../th-TH/README.md) | [Türkçe](../tr-TR/README.md) | [Українська](../uk-UA/README.md) | [اردو](../ur-PK/README.md) | [Tiếng Việt](../vi-VN/README.md) | [isiXhosa](../xh-ZA/README.md) | [Yorùbá](../yo-NG/README.md) | [繁體中文](../zh-TW/README.md) | [isiZulu](../zu-ZA/README.md)
<!-- translations:end -->

<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="../../images/logo_dark.png">
    <img src="../../images/logo.png" width="200" height="200" alt="Programu-jalizi ya SmokePing ya Cacti: kakti iliyovaa beji yenye grafu ya SmokePing">
  </picture>
</p>

# Programu-jalizi ya SmokePing kwa Cacti

[![CI](https://github.com/somethingwithproof/plugin_smokeping/actions/workflows/plugin-ci-workflow.yml/badge.svg)](https://github.com/somethingwithproof/plugin_smokeping/actions/workflows/plugin-ci-workflow.yml)
[![License: GPL-2.0-or-later](https://img.shields.io/badge/license-GPL--2.0--or--later-blue.svg)](../../../LICENSE)
![PHP >= 8.2](https://img.shields.io/badge/php-%3E%3D8.2-777bb4.svg)
![Cacti >= 1.2.31](https://img.shields.io/badge/cacti-%3E%3D1.2.31-2e7d32.svg)
[![Quality Gate Status](https://sonarcloud.io/api/project_badges/measure?project=somethingwithproof_plugin_smokeping&metric=alert_status)](https://sonarcloud.io/summary/new_code?id=somethingwithproof_plugin_smokeping)
[![Coverage](https://sonarcloud.io/api/project_badges/measure?project=somethingwithproof_plugin_smokeping&metric=coverage)](https://sonarcloud.io/summary/new_code?id=somethingwithproof_plugin_smokeping)
[![Security Rating](https://sonarcloud.io/api/project_badges/measure?project=somethingwithproof_plugin_smokeping&metric=security_rating)](https://sonarcloud.io/summary/new_code?id=somethingwithproof_plugin_smokeping)
[![Reliability Rating](https://sonarcloud.io/api/project_badges/measure?project=somethingwithproof_plugin_smokeping&metric=reliability_rating)](https://sonarcloud.io/summary/new_code?id=somethingwithproof_plugin_smokeping)
![SmokePing: read-only](https://img.shields.io/badge/smokeping-read--only-6b7280.svg)

Programu-jalizi ya Cacti 1.2 inayoleta vipimo vya ucheleweshaji (latency) na upotevu wa pakiti vya SmokePing katika Cacti kama vifaa vya kawaida, vyanzo vya data, na grafu. Grafu za smoke huwekwa karibu na kifaa zinachomiliki, chini ya ruhusa za Cacti, na Thold na Maint zinaweza kutoa tahadhari na kuzinyamazisha. Ni utekelezaji huru kwa ajili ya Cacti na si sehemu ya, wala haijaidhinishwa na, [SmokePing](https://oss.oetiker.ch/smokeping/), ambao waandishi wake wa awali ni Tobias Oetiker na Niko Tyni. Programu-jalizi hii haijumuishi wala haiendeshi msimbo wa SmokePing: inasoma usanidi na faili za RRD ambazo SmokePing huandika, na violezo vyake vya grafu vimejengwa kuchora mfululizo uleule kama grafu za SmokePing yenyewe.

> **Hali: inaendelea kutengenezwa. Hali ya uchunguzi (awamu ya 1) imetekelezwa kwenye `main` (1.0.0-rc.1) ikiwa na uchujaji wa mwendeshaji, uhamishaji wa CSV, ugunduzi wa RRD zisizo na mmiliki, tafsiri ya tahadhari za Thold, ndoano za muunganisho wa mfumo ikolojia, na urudufishaji wa mkusanyaji wa mbali.** Programu-jalizi hii inasoma usanidi wa SmokePing na faili za RRD, inaonyesha hali yake, inaunda vifaa vya Cacti, vyanzo vya data, na grafu kwa ajili yake, na kulisha Thold na Maint. Haiandiki chochote kwenye SmokePing. Hali ya usimamizi (programu-jalizi kuandika usanidi wa SmokePing) ni awamu ya baadaye, haijaanza, na haipo katika toleo hili. Majaribio kwenye mifumo halisi na ulinganifu wa karibu zaidi na maelezo ya grafu ya SmokePing bado yako wazi katika hatua ya 4. Tazama [docs/install.md](../../install.md) ili kuanza.

## Majukumu ya ufikiaji

| Eneo | Ufikiaji |
| --- | --- |
| SmokePing View | Kurasa zilizopo za ufuatiliaji. |
| SmokePing Operator | Ufuatiliaji pamoja na ukurasa wa Uendeshaji na uthibitisho wa kibinafsi wa uchambuzi wa awali. |
| SmokePing Admin | Ufikiaji wa Operator pamoja na usanidi uliopo na vitendo vya kiutawala. |

Baada ya kunakili faili za uboreshaji, fungua ukurasa wa Plugins kama msimamizi ili kusajili
eneo jipya. Gawa eneo la Operator waziwazi chini ya **Configuration > Users** au vikundi vya watumiaji.
Uboreshaji huhifadhi vitambulisho na ruhusa zilizopo za maeneo ya View na Admin. Ufikiaji wa Admin
hurithi ufikiaji wa Operator, na ufikiaji wa Operator hurithi ufikiaji wa View kwa kila ombi;
hii haiongezi ruhusa zilizohifadhiwa kwa watumiaji au vikundi. Mipangilio ya programu-jalizi pia inahitaji
ufikiaji wa SmokePing Admin pamoja na ruhusa ya Settings ya Cacti.

Ruhusa za vifaa vya Cacti bado zinazuia safumlalo na vitendo vinavyopatikana kwa kila mtumiaji; vipengee visivyohusishwa vinahitaji msimamizi.
Vitambulisho vya kuingia, usanidi, ulinganishaji, ufutaji na mabadiliko mengine ya kiutawala
hubaki katika kurasa za kiutawala. Uthibitisho wa utambuzi hudumu tu katika kipindi cha sasa
cha mtumiaji, kwa muda usiozidi saa moja na tu mradi uchunguzi uleule unasalia kuwa
mpya. Uchunguzi mpya au uliobadilika hubatilisha uthibitisho huo. Kamwe hauashirii kipimo kilichoshindwa
kuwa kizima wala haunyimizi tahadhari.

## Picha za skrini

Imeingizwa kutoka mtandao halisi wa SmokePing 2.8.2 hadi Cacti 1.2.31, saa 12 zilizopita. Kila kifaa lengwa kina grafu ya smoke kwa master na moja kwa kila slave (`lon1`, `sgp1`), zikiwa zimepangwa chini ya sehemu yake ya SmokePing kwenye mti wa grafu.

![Mwonekano wa mti wa Cacti wa lengo la Bangalore: grafu za smoke kutoka master na slave za lon1 na sgp1](../../images/screenshot-tree-asia.png)

![Mwonekano wa mti wa Cacti wa lengo la London: slave ya lon1 katika eneo lilelile inaonyesha ucheleweshaji wa maikrosekunde pamoja na smoke inayoonekana](../../images/screenshot-tree-europe.png)

## Kwa nini

SmokePing hupima usambazaji wa ucheleweshaji na upotevu wa pakiti vizuri, na grafu zake za smoke huonyesha mabadiliko ambayo grafu ya wastani pekee huficha. Ikiendeshwa sambamba na Cacti, huacha mapengo matatu:

- **Udhibiti tofauti wa ufikiaji.** CGI ya SmokePing haijui chochote kuhusu watumiaji, vikundi, au ruhusa za vifaa za Cacti.
- **Hakuna muktadha wa kifaa.** Ucheleweshaji na vihesabu vya kiolesura vya kifaa kilekile huishi katika zana tofauti.
- **Hakuna njia ya kuingia kwenye tahadhari za Cacti.** Thold na Maint hufanya kazi kwenye vyanzo vya data na vifaa vya Cacti, kwa hivyo tahadhari za SmokePing husanidiwa na kunyamazishwa kando.

Programu-jalizi hii huziba mapengo haya kwa kuwakilisha kila lengo la SmokePing kama vipengee vya Cacti vinavyoelekeza kwenye faili za RRD zilizopo za SmokePing, pamoja na seti ndogo ya thamani za muhtasari zilizokusanywa. Inatumia tena uchoraji wa grafu, ruhusa, Thold, na Maint za Cacti badala ya kujenga matoleo sambamba ya zana hizo. Urudufishaji wa usanidi unaozingatia poller (`replicate_out`) husukuma majedwali ya usanidi ya poller za mbali huku ukiweka hali ya ndani (poller 1) na akiba za uchunguzi za mkusanyaji wa ndani zikiwa zimetengwa.

## Wigo na hali

| Awamu | Wigo | Hatua muhimu | Hali |
|---|---|---|---|
| 0, utafiti | Jedwali la uoanifu, uthibitishaji wa tabia ya mfumo mama, jaribio la usahihi wa grafu | [M1](https://github.com/somethingwithproof/plugin_smokeping/milestone/1) | Matokeo katika `docs/research`; baadhi ya masuala ya utafiti yako wazi |
| 1, uchunguzi | Uingizaji wa orodha ya vipengee, vifaa vya Cacti, grafu za smoke na muhtasari, tahadhari za Thold na tafsiri ya tahadhari, unyamazishaji wa Maint, mionekano iliyochujwa, uhamishaji wa CSV, ugunduzi wa RRD zisizo na mmiliki, ndoano za mfumo ikolojia | [M2](https://github.com/somethingwithproof/plugin_smokeping/milestone/2), [M3](https://github.com/somethingwithproof/plugin_smokeping/milestone/3), [M4](https://github.com/somethingwithproof/plugin_smokeping/milestone/4) | Imetekelezwa; majaribio yako wazi |
| 2, usimamizi | Eneo la majina la usanidi linalomilikiwa na Cacti, wasifu wa probe za ICMP na TCP, marekebisho, uthibitishaji, upakiaji upya, urejeshaji nyuma | [M5](https://github.com/somethingwithproof/plugin_smokeping/milestone/5) | Imepangwa, haijaanza |
| 3, mbali | Hoja zinazozingatia poller na urudufishaji wa majedwali wa `replicate_out` kwa wakusanyaji wa data wa mbali (`RemoteReplication`); uthibitishaji kamili wa uwekaji wa SmokePing wa wakusanyaji wengi | [M6](https://github.com/somethingwithproof/plugin_smokeping/milestone/6) | Urudufishaji na upeo wa poller vimetekelezwa; uthibitishaji wa uwekaji wa wakusanyaji wengi uko wazi |

Mitiririko ya kazi ya mwendeshaji iliyotekelezwa, amri za CLI, na ndoano za mfumo ikolojia:

- **Orodha ya vipengee na uchujaji wa malengo (`smokeping_inventory.php`, `smokeping.php`)**: `TargetFilter`, `TargetRepository::filteredForPoller()`, `InventoryPage`, na `ViewPage` hutoa utafutaji (`filter`), probe (`probe`), uwepo katika orodha (`presence`), hali ya ulinganishaji (`mapping`), hali ya upya (`state`), na upangaji wa kurasa wa hiari (`rows`: `All`, `25`, `50`, `100`, `200`). Mwonekano wa kifaa hudhibitiwa katika SQL kabla ya kuhesabu ili jumla zisifichue kamwe vifaa vilivyofichwa.
- **Uhamishaji wa CSV (`smokeping.php?action=export_csv`)**: `CsvExporter` hutiririsha matokeo ya RFC 4180 CRLF kwa mwonekano uliochujwa unaotumika chini ya ruhusa za vifaa za mpigaji, ikiweka apostrofi mwanzoni mwa seli zinazoanza na `=`, `+`, `-`, `@`, tabo, au carriage return ili kubatilisha fomula za lahajedwali.
- **Ugunduzi wa RRD zisizo na mmiliki (`smokeping_health.php`)**: `OrphanRrdScanner` na `HealthCheck::orphanRrds()` hufanya ukaguzi wenye mipaka wa sehemu ya SmokePing iliyopachikwa kwenye poller ya kisomaji, wakiruka `__cgi`, `__sortercache`, na viungo vya ishara, na kulinganisha faili za `.rrd` zisizo na mmiliki na mapendekezo ya kuunganisha tena yaliyo wazi.
- **Ubadilishaji wa SmokePing kwenda Thold (`smokeping_health.php`, `cli/smokeping.php`)**: `TholdTranslator` na `TholdGenerator` husoma ufafanuzi wa SmokePing `*** Alerts ***` na kutafsiri mifumo inayotumika ya upotevu mfululizo kuwa vizingiti linganifu vya `loss_pct` vya Thold bila kubadilisha faili za usanidi za SmokePing. Mtiririko wa wavuti hutumia mwonekano wa awali wa GET (`action=thold_preview`, `HealthPage::renderTholdPreview()`) ikifuatiwa na POST iliyolindwa na CSRF (`action=thold_create`). Mifumo isiyotumika (tahadhari za RTT, upunguzaji wa wildcard, viwango mchanganyiko) hurukwa bila kuandika.
- **CLI ya msimamizi (`cli/smokeping.php`)**: inasaidia `provision <target path> [--device=ID] [--location=NAME]`, `relink <old target path> <new target path> [--location=NAME]`, `thold-generate <target path> [--alert=NAME] [--apply]`, `size-graphs`, `upgrade-graphs`, `export [--format=yaml|xml] [--file=PATH]`, `import --file=PATH [--format=yaml|xml] [--apply --confirm=PLAN]`, na `import-smokeping [--dry-run | --apply --confirm=PLAN] [--file=PATH] [--sections=A,B/C] [--device-per=host|target] [--device-template=ID] [--poller=ID] [--site=ID] [--tree=ID | --tree-name=NAME]`.
- **Ndoano za mfumo ikolojia (`setup.php`, `Schema::HOOKS`)**: husajili ndoano 21, zikiwemo `abilities_register` (`AbilitiesProvider`: `smokeping-list-targets` na `smokeping-get-health` za kusoma pekee, zilizodhibitiwa na eneo la View dhidi ya kitambulisho cha mtumiaji anayepiga, zisizorejesha siri zozote), `doctor_register_checks` (`DoctorChecks`: `smokeping_stale_targets`, `smokeping_freshness_breaches`, `smokeping_orphan_rrds`, `smokeping_config_path`), `casc_register_providers` (`CascProvider`: `smokeping` YAML/XML `export`, `validate`, `plan`, na `apply` ikitegemea `ConfigCodec` na `ConfigService`), na `replicate_out` (`RemoteReplication`: ulandanishaji unaozingatia poller wa `plugin_smokeping_target`, `plugin_smokeping_mapping`, `plugin_smokeping_object`, na `plugin_smokeping_relink` kwa poller za mbali zenye `poller_id > 1`).

Nje ya wigo: injini ya tahadhari inayomilikiwa na programu-jalizi, injini ya ripoti, huduma ya usuli (daemon) au itifaki maalum ya mtandao, ukaguzi unaosimamiwa wa HTTP/DNS/huduma (servcheck hushughulikia hayo), na kuandika kwenye faili za RRD za SmokePing.

## Malengo ya uoanifu

| Kipengele | Lengo |
|---|---|
| Cacti | 1.2.31 au mpya zaidi katika 1.2.x, bila viraka vya msingi |
| PHP | 8.2 au mpya zaidi; `setup.php` hubaki ikichanganulika kwenye 8.0 ili mazingira ya uendeshaji yasiyotumika yapate katazo badala ya kuharibu kiolesura cha Cacti |
| SmokePing | 2.9.0, 2.8.2, na 2.7.3 (Ubuntu 22.04 pekee, hadi 2027-06-01) |
| Thold, Maint | Hakuna mabadiliko yanayohitajika |
| Mfululizo | 10,000 kwa kila usakinishaji kwa ping 20 na hatua ya 300 s ([utendaji](../../performance.md#supported-series-count)) |

## Nyaraka

| | |
|---|---|
| [Sakinisha](../../install.md) | Mahitaji, uunganishaji wa kusoma tu wa saraka ya data ya SmokePing, usakinishaji, kontena, matoleo yanayotumika |
| [Endesha](../../operations.md) | Maana ya kila hali na ushahidi wake, ukaguzi wa afya, tabia wakati ingizo linaposhindwa |
| [Ongeza malengo kwenye Cacti](../../provisioning.md) | Lengo moja, vifaa vingi, kuunganisha tena, ruhusa |
| [Tahadhari](../../alerting.md) | Thamani za muhtasari, muda wa Thold, uchakavu wa data, data iliyochelewa, mwingiliano na tahadhari za SmokePing, Maint |
| [Umbizo la usanidi](../../configuration-format.md) | Uhamishaji na uingizaji wa YAML na XML |
| [Nakala rudufu na urejeshaji](../../backup-restore.md) | Nini cha kuwekea nakala rudufu na jinsi urejeshaji unavyoendelea |
| [Utendaji](../../performance.md) | Vipimo vya ndani na vya mazingira ya utoaji katika mfululizo 100, 1,000, na 10,000, na idadi ya mfululizo inayotumika: 10,000 kwa kila usakinishaji |
| [Uthibitishaji wa kushindwa](../../failure-qualification.md) | Hali za kushindwa na jinsi kila moja inavyojaribiwa |
| [Utoaji wa matoleo](../../RELEASING.md) | Utaratibu wa utoaji: pull request, lebo iliyosainiwa, kile ambacho mtiririko wa kazi hufanya, jinsi ya kuthibitisha matokeo |
| [Kumbukumbu ya matoleo](../../release.md) | Toleo, orodha ya mabadiliko, na kumbukumbu ya matoleo |
| [Majaribio](../../testing.md) | Seti za majaribio, kile ambacho CI huendesha, na seti ya moja kwa moja dhidi ya SmokePing halisi |

## Muundo

- [docs/PLAN.md](../../PLAN.md) ni mpango kamili: usanifu wa mfumo, ukweli wa mfumo mama unaotegemewa (pamoja na vyanzo), muundo wa hali, mipaka ya usalama, hesabu za utendaji, majaribio, na hatari.
- [docs/decisions/](../../decisions) huhifadhi rekodi za maamuzi ya usanifu kwa chaguo ambazo tayari zimefanywa.
- [docs/REQUIREMENTS.md](../../REQUIREMENTS.md) huunganisha kila kitambulisho cha hitaji na suala lake.
- Uthibitishaji wa ndani huendesha ukaguzi wa sehemu za Pest na sheria za msimbo chanzo (`pest --configuration=phpunit.xml`), PHPStan Kiwango cha 9 (`CACTI_PATH=<cacti-dir> phpstan analyse -c phpstan.neon --no-progress`), ukaguzi wa sintaksia ya sehemu za kuingilia (`php -l` kwenye `*.php`, `include/*.php`, `scripts/*.php`, `cli/*.php`), na ukaguzi wa tafsiri (`sh locales/build_gettext.sh --check`). Tazama [docs/testing.md](../../testing.md) kwa seti za majaribio ya muunganisho na ya mwisho-hadi-mwisho ya kontena.

```mermaid
flowchart LR
    SP[Daemon ya SmokePing] --> RRD[(Faili za RRD)]
    RRD -->|kusoma tu| R[Kisomaji kwenye poller ya Cacti iliyo karibu]
    RRD -->|kusoma tu| G[Violezo vya grafu vya Cacti]
    R --> SS[Vyanzo vya data vya muhtasari wa seva ya hati]
    SS -->|poller_output| T[Thold na Maint]
    G --> P[Ruhusa za grafu za Cacti]
```

## Jinsi kazi inavyofuatiliwa

- **Hatua muhimu (Milestones)** hufuata hatua za mpango. Kila moja ina suala kuu (epic) linaloorodhesha masuala yake madogo na ushahidi wa kukamilika.
- **Masuala** yanaainishwa kwa lebo: `type: requirement`, `type: research`, `type: decision`, `type: epic`. Masuala ya mahitaji hubainisha mahitaji yenye nambari yanayoweza kujaribiwa (`REQ-AREA-NNN`) na vigezo vya kukubalika.
- **Lebo** pia hubeba `area:`, `phase:`, `priority:`, na `status:`. Tazama [CONTRIBUTING.md](../../../CONTRIBUTING.md#labels).
- [Ubao wa mradi](https://github.com/users/somethingwithproof/projects/6) unaonyesha masuala yote katika hatua zote muhimu.

## Kuchangia

Soma [CONTRIBUTING.md](../../../CONTRIBUTING.md). Commit zinahitaji sahihi ya DCO. Majadiliano ya muundo hufanyika kwenye masuala; maamuzi yaliyo wazi hubeba lebo ya `type: decision`.

## Usalama

Ripoti udhaifu wa kiusalama kwa faragha; tazama [SECURITY.md](../../../SECURITY.md).

## Leseni

GNU General Public License v2.0, leseni ileile na ya Cacti na programu-jalizi zake. Tazama [LICENSE](../../../LICENSE).
