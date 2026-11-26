<!-- Source: README.md; SHA256: 17905aa1eac76a7d10dab526734605629fa3d1ada56397642ad4154c5be1feff -->

<!-- translations:start -->
[English](../../../README.md) | [简体中文](../zh-CN/README.md) | [हिन्दी](../hi-IN/README.md) | [Español (España)](../es-ES/README.md) | [Español (Latinoamérica)](../es-419/README.md) | [العربية (السعودية)](../ar-SA/README.md) | [Afrikaans](../af-ZA/README.md) | [አማርኛ](../am-ET/README.md) | [العربية (مصر)](../ar-EG/README.md) | [العربية (المغرب)](../ar-MA/README.md) | [বাংলা](../bn-BD/README.md) | [Bosanski](../bs-BA/README.md) | [Deutsch](../de-DE/README.md) | [فارسی](../fa-IR/README.md) | [Filipino](../fil-PH/README.md) | [Français](../fr-FR/README.md) | [Français (Maroc)](../fr-MA/README.md) | [Français (Sénégal)](../fr-SN/README.md) | [Hausa](../ha-NG/README.md) | [עברית](../he-IL/README.md) | [Hrvatski](../hr-HR/README.md) | [Bahasa Indonesia](../id-ID/README.md) | [Igbo](../ig-NG/README.md) | [Italiano](../it-IT/README.md) | [日本語](../ja-JP/README.md) | [한국어](../ko-KR/README.md) | [Malagasy](../mg-MG/README.md) | [मराठी](../mr-IN/README.md) | [Bahasa Melayu](../ms-MY/README.md) | [Nederlands](../nl-NL/README.md) | [Polski](../pl-PL/README.md) | [Português (Brasil)](../pt-BR/README.md) | [Русский](../ru-RU/README.md) | [Kinyarwanda](../rw-RW/README.md) | [Soomaali](../so-SO/README.md) | [Српски](../sr-RS/README.md) | [Svenska](README.md) | [Kiswahili](../sw-KE/README.md) | [தமிழ்](../ta-IN/README.md) | [తెలుగు](../te-IN/README.md) | [ไทย](../th-TH/README.md) | [Türkçe](../tr-TR/README.md) | [Українська](../uk-UA/README.md) | [اردو](../ur-PK/README.md) | [Tiếng Việt](../vi-VN/README.md) | [isiXhosa](../xh-ZA/README.md) | [Yorùbá](../yo-NG/README.md) | [繁體中文](../zh-TW/README.md) | [isiZulu](../zu-ZA/README.md)
<!-- translations:end -->

<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="../../images/logo_dark.png">
    <img src="../../images/logo.png" width="200" height="200" alt="SmokePing-insticksmodul för Cacti: en kaktus som bär ett märke med en SmokePing-graf">
  </picture>
</p>

# SmokePing-insticksmodul för Cacti

[![CI](https://github.com/somethingwithproof/plugin_smokeping/actions/workflows/plugin-ci-workflow.yml/badge.svg)](https://github.com/somethingwithproof/plugin_smokeping/actions/workflows/plugin-ci-workflow.yml)
[![License: GPL-2.0-or-later](https://img.shields.io/badge/license-GPL--2.0--or--later-blue.svg)](../../../LICENSE)
![PHP >= 8.2](https://img.shields.io/badge/php-%3E%3D8.2-777bb4.svg)
![Cacti >= 1.2.31](https://img.shields.io/badge/cacti-%3E%3D1.2.31-2e7d32.svg)
[![Quality Gate Status](https://sonarcloud.io/api/project_badges/measure?project=somethingwithproof_plugin_smokeping&metric=alert_status)](https://sonarcloud.io/summary/new_code?id=somethingwithproof_plugin_smokeping)
[![Coverage](https://sonarcloud.io/api/project_badges/measure?project=somethingwithproof_plugin_smokeping&metric=coverage)](https://sonarcloud.io/summary/new_code?id=somethingwithproof_plugin_smokeping)
[![Security Rating](https://sonarcloud.io/api/project_badges/measure?project=somethingwithproof_plugin_smokeping&metric=security_rating)](https://sonarcloud.io/summary/new_code?id=somethingwithproof_plugin_smokeping)
[![Reliability Rating](https://sonarcloud.io/api/project_badges/measure?project=somethingwithproof_plugin_smokeping&metric=reliability_rating)](https://sonarcloud.io/summary/new_code?id=somethingwithproof_plugin_smokeping)
![SmokePing: read-only](https://img.shields.io/badge/smokeping-read--only-6b7280.svg)

En insticksmodul för Cacti 1.2 som för in latens- och paketförlustmätningar från SmokePing i Cacti som vanliga enheter, datakällor och grafer. Smoke-grafer hamnar då bredvid den enhet de tillhör, under Cactis behörighetssystem, och Thold och Maint kan larma på och undertrycka dem. Det är en oberoende implementering för Cacti och ingår inte i, och stöds inte av, [SmokePing](https://oss.oetiker.ch/smokeping/), vars ursprungliga upphovspersoner är Tobias Oetiker och Niko Tyni. Insticksmodulen varken innehåller eller kör SmokePings kod: den läser konfigurationen och de RRD-filer som SmokePing skriver, och dess grafmallar är byggda för att rita samma dataserier som SmokePings egna grafer.

> **Status: under utveckling. Observationsläget (fas 1) är implementerat på `main` och släppt som förhandsversionen 1.0.0-rc.1.** Insticksmodulen läser SmokePings konfiguration och RRD-filer, visar deras tillstånd, skapar Cacti-enheter, datakällor och grafer för dem samt matar Thold och Maint. Den skriver ingenting till SmokePing. Hanterat läge (där insticksmodulen skriver SmokePing-konfiguration) och fjärrinsamlare är senare faser som inte har påbörjats och inte ingår i denna version. Pilotkörningar i verkliga installationer och närmare överensstämmelse med SmokePings grafförklaring är fortfarande öppna i milstolpe 4. Se [docs/install.md](../../install.md) för att komma igång.

## Åtkomstroller

| Roll (Realm) | Åtkomst |
| --- | --- |
| SmokePing View | Befintliga övervakningssidor. |
| SmokePing Operator | Övervakning samt sidan Operations och personliga triage-kvittenser. |
| SmokePing Admin | Operatörsåtkomst samt befintliga konfigurations- och administrationsåtgärder. |

Efter att uppgraderingsfilerna har kopierats öppnar du sidan Plugins som administratör för att registrera den nya rollen. Tilldela rollen SmokePing Operator explicit under **Configuration > Users** eller användargrupper. Uppgraderingar behåller befintliga roll-ID:n och tilldelningar för View och Admin. Admin-åtkomst ärver Operator-åtkomst, och Operator-åtkomst ärver View-åtkomst vid varje begäran; detta lägger inte till lagrade behörigheter hos användare eller grupper. Inställningar för insticksmodulen kräver även SmokePing Admin-åtkomst utöver Cactis behörighet Settings.

Enhetsbehörigheter i Cacti begränsar fortfarande vilka rader och åtgärder som är tillgängliga för varje användare; omappade objekt kräver en administratör. Inloggningsuppgifter, konfiguration, mappning, borttagning och andra administrativa ändringar ligger kvar på administrationssidorna. Kvittenser gäller endast i den aktuella användarens session, i högst en timme och bara så länge samma observation förblir färsk. Nya eller ändrade observationer gör dem ogiltiga. De markerar aldrig en misslyckad mätning som frisk och undertrycker aldrig larm.

## Skärmbilder

Importerat från ett aktivt SmokePing 2.8.2-nätverk till Cacti 1.2.31, senaste 12 timmarna. Varje målenhet har en smoke-graf för mastern och en för varje slav (`lon1`, `sgp1`), placerade under sin SmokePing-sektion i grafträdet.

![Trädvy i Cacti för målet Bangalore: smoke-grafer från mastern samt slavarna lon1 och sgp1](../../images/screenshot-tree-asia.png)

![Trädvy i Cacti för målet London: slaven lon1 i samma region visar latens på mikrosekundnivå med synlig smoke](../../images/screenshot-tree-europe.png)

## Varför

SmokePing mäter latensfördelning och paketförlust väl, och dess smoke-grafer visar variationer som en graf med enbart medelvärden döljer. När det körs sida vid sida med Cacti uppstår tre luckor:

- **Separat åtkomstkontroll.** SmokePings CGI känner inte till Cactis användare, grupper eller enhetsbehörigheter.
- **Ingen enhetskontext.** Latens och gränssnittsräknare för samma enhet finns i olika verktyg.
- **Ingen väg in i Cactis larmsystem.** Thold och Maint arbetar på Cactis datakällor och enheter, så SmokePing-larm konfigureras och undertrycks separat.

Insticksmodulen stänger dessa luckor genom att representera varje SmokePing-mål som Cacti-objekt som pekar på SmokePings befintliga RRD-filer, plus en liten uppsättning pollade sammanfattningsvärden. Den återanvänder Cactis grafer, behörigheter, Thold och Maint i stället för att bygga parallella versioner av dem. Stöd för Cactis fjärrdatainsamlare planeras för fas 3 och ingår inte i denna version.

## Omfattning och status

| Fas | Omfattning | Milstolpar | Status |
|---|---|---|---|
| 0, research | Kompatibilitetsmatris, bekräftelse av uppströmsbeteende, förstudie av graftrohet | [M1](https://github.com/somethingwithproof/plugin_smokeping/milestone/1) | Resultat i `docs/research`; vissa research-ärenden är öppna |
| 1, observation | Inventeringsimport, Cacti-enheter, smoke- och sammanfattningsgrafer, Thold-larm, Maint-undertryckning, hälsosida | [M2](https://github.com/somethingwithproof/plugin_smokeping/milestone/2), [M3](https://github.com/somethingwithproof/plugin_smokeping/milestone/3), [M4](https://github.com/somethingwithproof/plugin_smokeping/milestone/4) | Släppt som 1.0.0-rc.1; pilotkörningar öppna |
| 2, hanterat läge | Cacti-ägd konfigurationsnamnrymd, ICMP- och TCP-probprofiler, revisioner, validering, omladdning, återställning | [M5](https://github.com/somethingwithproof/plugin_smokeping/milestone/5) | Planerad, ej påbörjad |
| 3, fjärrinsamling | SmokePing på Cactis fjärrdatainsamlare, slavar som mätplatser | [M6](https://github.com/somethingwithproof/plugin_smokeping/milestone/6) | Planerad, ej påbörjad |

Utanför omfattningen: en egen larmmotor i insticksmodulen, en rapportmotor, en egen daemon eller ett eget nätverksprotokoll, hanterade HTTP-/DNS-/tjänstekontroller (servcheck täcker dessa) samt skrivning till SmokePings RRD-filer.

## Kompatibilitetsmål

| Komponent | Mål |
|---|---|
| Cacti | 1.2.31 eller senare i 1.2.x, inga ändringar i kärnkoden |
| PHP | 8.2 eller senare; `setup.php` förblir tolkningsbar på 8.0 så att en körtid som inte stöds får ett avslag i stället för ett trasigt Cacti-gränssnitt |
| SmokePing | 2.9.0, 2.8.2 och 2.7.3 (endast Ubuntu 22.04, fram till 2027-06-01) |
| Thold, Maint | Inga ändringar krävs |
| Serier | 10 000 per installation med 20 pingar och ett steg på 300 s ([prestanda](../../performance.md#supported-series-count)) |

## Dokumentation

| | |
|---|---|
| [Installation](../../install.md) | Krav, skrivskyddad montering av SmokePings datakatalog, installation, containrar, versioner som stöds |
| [Drift](../../operations.md) | Vad varje tillstånd betyder och dess evidens, hälsokontroller, beteende när indata fallerar |
| [Lägg till mål i Cacti](../../provisioning.md) | Ett mål, många enheter, återlänkning, behörigheter |
| [Larm](../../alerting.md) | Sammanfattningsvärden, Thold-varaktigheter, föråldrade data, sena data, överlappning med SmokePings larm, Maint |
| [Konfigurationsformat](../../configuration-format.md) | Export och import i YAML och XML |
| [Säkerhetskopiering och återställning](../../backup-restore.md) | Vad som ska säkerhetskopieras och hur en återställning återupptas |
| [Prestanda](../../performance.md) | Mätningar lokalt och på release-runner vid 100, 1 000 och 10 000 serier samt maximalt antal serier som stöds: 10 000 per installation |
| [Felkvalificering](../../failure-qualification.md) | Felscenarierna och hur varje scenario testas |
| [Utgivning](../../RELEASING.md) | Utgivningsrutinen: pull request, signerad tagg, vad arbetsflödet gör, hur resultatet verifieras |
| [Utgivningsarkiv](../../release.md) | Version, ändringslogg och utgivningsarkivet |
| [Testning](../../testing.md) | Testsviterna, vad CI kör och livesviten mot en riktig SmokePing-instans |

## Design

- [docs/PLAN.md](../../PLAN.md) är den fullständiga planen: arkitektur, uppströmsfakta som den bygger på (med källor), tillståndsmodell, säkerhetsgränser, prestandaberäkningar, testning och risker.
- [docs/decisions/](../../decisions) innehåller arkitekturbeslut (ADR) för val som redan gjorts.
- [docs/REQUIREMENTS.md](../../REQUIREMENTS.md) mappar varje krav-ID till dess ärende.

```mermaid
flowchart LR
    SP[SmokePing-daemon] --> RRD[(RRD-filer)]
    RRD -->|skrivskyddad| R[Läsare på den intilliggande Cacti-pollern]
    RRD -->|skrivskyddad| G[Grafmallar i Cacti]
    R --> SS[Sammanfattande datakällor för skriptserver]
    SS -->|poller_output| T[Thold och Maint]
    G --> P[Grafbehörigheter i Cacti]
```

## Hur arbetet följs upp

- **Milstolpar** följer planens milstolpar. Var och en har ett epic-ärende som listar dess delärenden och slutbevis.
- **Ärenden** typas med etiketter: `type: requirement`, `type: research`, `type: decision`, `type: epic`. Kravärenden anger numrerade, testbara krav (`REQ-AREA-NNN`) och acceptanskriterier.
- **Etiketter** anger även `area:`, `phase:`, `priority:` och `status:`. Se [CONTRIBUTING.md](../../../CONTRIBUTING.md#labels).
- [Projekttavlan](https://github.com/users/somethingwithproof/projects/6) visar alla ärenden över alla milstolpar.

## Bidra

Läs [CONTRIBUTING.md](../../../CONTRIBUTING.md). Commits kräver en DCO-signatur (`DCO sign-off`). Designdiskussioner sker i ärenden; öppna beslut har etiketten `type: decision`.

## Säkerhet

Rapportera sårbarheter privat; se [SECURITY.md](../../../SECURITY.md).

## Licens

GNU General Public License v2.0, samma licens som Cacti och dess insticksmoduler. Se [LICENSE](../../../LICENSE).
