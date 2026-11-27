<!-- Source: README.md; SHA256: b41ec705327127cc34ff07d1514a141262752f99c5e2296fdc323d584a777b84 -->

<!-- translations:start -->
[English](../../../README.md) | [简体中文](../zh-CN/README.md) | [हिन्दी](../hi-IN/README.md) | [Español (España)](../es-ES/README.md) | [Español (Latinoamérica)](../es-419/README.md) | [العربية (السعودية)](../ar-SA/README.md) | [Afrikaans](../af-ZA/README.md) | [አማርኛ](../am-ET/README.md) | [العربية (مصر)](../ar-EG/README.md) | [العربية (المغرب)](../ar-MA/README.md) | [বাংলা](../bn-BD/README.md) | [Bosanski](../bs-BA/README.md) | [Deutsch](../de-DE/README.md) | [فارسی](../fa-IR/README.md) | [Filipino](../fil-PH/README.md) | [Français](../fr-FR/README.md) | [Français (Maroc)](../fr-MA/README.md) | [Français (Sénégal)](../fr-SN/README.md) | [Hausa](../ha-NG/README.md) | [עברית](../he-IL/README.md) | [Hrvatski](../hr-HR/README.md) | [Bahasa Indonesia](../id-ID/README.md) | [Igbo](../ig-NG/README.md) | [Italiano](../it-IT/README.md) | [日本語](../ja-JP/README.md) | [한국어](../ko-KR/README.md) | [Malagasy](../mg-MG/README.md) | [मराठी](../mr-IN/README.md) | [Bahasa Melayu](../ms-MY/README.md) | [Nederlands](../nl-NL/README.md) | [Polski](../pl-PL/README.md) | [Português (Brasil)](../pt-BR/README.md) | [Русский](../ru-RU/README.md) | [Kinyarwanda](../rw-RW/README.md) | [Soomaali](../so-SO/README.md) | [Српски](../sr-RS/README.md) | [Svenska](../sv-SE/README.md) | [Kiswahili](../sw-KE/README.md) | [தமிழ்](../ta-IN/README.md) | [తెలుగు](../te-IN/README.md) | [ไทย](../th-TH/README.md) | [Türkçe](README.md) | [Українська](../uk-UA/README.md) | [اردو](../ur-PK/README.md) | [Tiếng Việt](../vi-VN/README.md) | [isiXhosa](../xh-ZA/README.md) | [Yorùbá](../yo-NG/README.md) | [繁體中文](../zh-TW/README.md) | [isiZulu](../zu-ZA/README.md)
<!-- translations:end -->

<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="../../images/logo_dark.png">
    <img src="../../images/logo.png" width="200" height="200" alt="SmokePing Cacti Eklentisi: SmokePing grafiği içeren bir rozet takan kaktüs">
  </picture>
</p>

# Cacti için SmokePing eklentisi

[![CI](https://github.com/somethingwithproof/plugin_smokeping/actions/workflows/plugin-ci-workflow.yml/badge.svg)](https://github.com/somethingwithproof/plugin_smokeping/actions/workflows/plugin-ci-workflow.yml)
[![License: GPL-2.0-or-later](https://img.shields.io/badge/license-GPL--2.0--or--later-blue.svg)](../../../LICENSE)
![PHP >= 8.2](https://img.shields.io/badge/php-%3E%3D8.2-777bb4.svg)
![Cacti >= 1.2.31](https://img.shields.io/badge/cacti-%3E%3D1.2.31-2e7d32.svg)
[![Quality Gate Status](https://sonarcloud.io/api/project_badges/measure?project=somethingwithproof_plugin_smokeping&metric=alert_status)](https://sonarcloud.io/summary/new_code?id=somethingwithproof_plugin_smokeping)
[![Coverage](https://sonarcloud.io/api/project_badges/measure?project=somethingwithproof_plugin_smokeping&metric=coverage)](https://sonarcloud.io/summary/new_code?id=somethingwithproof_plugin_smokeping)
[![Security Rating](https://sonarcloud.io/api/project_badges/measure?project=somethingwithproof_plugin_smokeping&metric=security_rating)](https://sonarcloud.io/summary/new_code?id=somethingwithproof_plugin_smokeping)
[![Reliability Rating](https://sonarcloud.io/api/project_badges/measure?project=somethingwithproof_plugin_smokeping&metric=reliability_rating)](https://sonarcloud.io/summary/new_code?id=somethingwithproof_plugin_smokeping)
![SmokePing: read-only](https://img.shields.io/badge/smokeping-read--only-6b7280.svg)

SmokePing gecikme ve paket kaybı ölçümlerini Cacti'ye standart aygıtlar, veri kaynakları ve grafikler olarak aktaran bir Cacti 1.2 eklentisidir. Smoke grafikleri böylece ait oldukları aygıtın yanında, Cacti izinleri altında yer alır; Thold ve Maint bu grafikler üzerinden uyarı üretebilir ve uyarıları bastırabilir. Bu eklenti Cacti için bağımsız bir uygulamadır; özgün yazarları Tobias Oetiker ve Niko Tyni olan [SmokePing](https://oss.oetiker.ch/smokeping/) projesinin bir parçası değildir ve onun tarafından onaylanmamıştır. Eklenti SmokePing kodunu içermez veya çalıştırmaz: SmokePing'in yazdığı yapılandırma ve RRD dosyalarını okur ve grafik şablonları SmokePing'in kendi grafikleriyle aynı serileri çizecek şekilde oluşturulmuştur.

> **Durum: geliştirme aşamasında. Gözlem modu (1. aşama) `main` dalında uygulanmış ve 1.0.0-rc.1 ön sürümü olarak yayımlanmıştır.** Eklenti SmokePing'in yapılandırma ve RRD dosyalarını okur, durumlarını gösterir, bunlar için Cacti aygıtları, veri kaynakları ve grafikleri oluşturur ve Thold ile Maint'i besler. SmokePing'e hiçbir şey yazmaz. Yönetilen mod (eklentinin SmokePing yapılandırmasını yazması) ve uzak toplayıcılar sonraki aşamalardır, henüz başlanmamıştır ve bu sürümde yer almaz. Gerçek kurulumlardaki pilot uygulamalar ve SmokePing grafik göstergesiyle daha yakın uyum 4. kilometre taşında hâlâ açıktır. Başlamak için [docs/install.md](../../install.md) dosyasına bakın.

## Erişim rolleri

| Yetki alanı | Erişim |
| --- | --- |
| SmokePing View | Mevcut izleme sayfaları. |
| SmokePing Operator | İzleme sayfalarına ek olarak İşlemler sayfası ve kişisel önceliklendirme onayları. |
| SmokePing Admin | Operator erişimine ek olarak mevcut yapılandırma ve yönetim işlemleri. |

Yükseltme dosyalarını kopyaladıktan sonra yeni yetki alanını kaydetmek için yönetici olarak Eklentiler (Plugins) sayfasını açın. Operator yetki alanını **Configuration > Users** veya kullanıcı grupları altında açıkça atayın.
Yükseltmeler mevcut View ve Admin yetki alanı kimliklerini ve izinlerini korur. Admin erişimi her istekte Operator erişimini, Operator erişimi ise View erişimini devralır; bu durum kullanıcılara veya gruplara depolanmış ek izinler eklemez. Eklenti ayarları, Cacti'nin Ayarlar (Settings) iznine ek olarak SmokePing Admin erişimi de gerektirir.

Cacti aygıt izinleri her kullanıcıya sunulan satırları ve eylemleri sınırlandırmaya devam eder; eşleştirilmemiş nesneler için yönetici gerekir.
Kimlik bilgileri, yapılandırma, eşleştirme, silme ve diğer yönetim değişiklikleri yönetim sayfalarında kalır. Onaylar yalnızca geçerli kullanıcının oturumunda, en fazla bir saat boyunca ve yalnızca aynı gözlem güncel kaldığı sürece geçerlidir. Yeni veya değişen gözlemler bunları geçersiz kılar. Onaylar başarısız bir ölçümü asla sağlıklı olarak işaretlemez veya uyarıları bastırmaz.

## Ekran görüntüleri

Canlı bir SmokePing 2.8.2 ağından Cacti 1.2.31'e aktarılmıştır, son 12 saat. Her hedefin aygıtı, grafik ağacındaki kendi SmokePing bölümü altında dosyalanmış olarak ana sunucu için bir ve her ikincil düğüm (`lon1`, `sgp1`) için birer smoke grafiğine sahiptir.

![Bangalore hedefinin Cacti ağaç görünümü: ana sunucudan ve lon1 ile sgp1 ikincil düğümlerinden gelen smoke grafikleri](../../images/screenshot-tree-asia.png)

![Londra hedefinin Cacti ağaç görünümü: aynı bölgedeki lon1 ikincil düğümü görünür smoke ile mikrosaniye düzeyinde gecikme gösteriyor](../../images/screenshot-tree-europe.png)

## Neden

SmokePing gecikme dağılımını ve paket kaybını iyi ölçer; smoke grafikleri ise yalnızca ortalama gösteren bir grafiğin gizlediği değişkenliği ortaya koyar. Cacti ile yan yana çalıştırıldığında üç boşluk bırakır:

- **Ayrı erişim denetimi.** SmokePing'in CGI arayüzü Cacti kullanıcıları, grupları veya aygıt izinleri hakkında hiçbir şey bilmez.
- **Aygıt bağlamı eksikliği.** Aynı aygıta ait gecikme ve arayüz sayaçları farklı araçlarda bulunur.
- **Cacti uyarı sistemine giden bir yol olmaması.** Thold ve Maint, Cacti veri kaynakları ve aygıtları üzerinde çalışır; bu nedenle SmokePing uyarıları ayrı olarak yapılandırılır ve bastırılır.

Eklenti, her SmokePing hedefini SmokePing'in mevcut RRD dosyalarına işaret eden Cacti nesneleri ve az sayıda yoklanan özet değer olarak temsil ederek bu boşlukları kapatır. Cacti'nin grafik, izin, Thold ve Maint özelliklerinin paralel sürümlerini geliştirmek yerine bunları yeniden kullanır. Cacti uzak veri toplayıcıları için destek 3. aşama için planlanmıştır ve bu sürümde yer almaz.

## Kapsam ve durum

| Aşama | Kapsam | Kilometre taşları | Durum |
|---|---|---|---|
| 0, araştırma | Uyumluluk matrisi, üst kaynak davranış doğrulaması, grafik sadakati ön çalışması | [M1](https://github.com/somethingwithproof/plugin_smokeping/milestone/1) | Bulgular `docs/research` içinde; bazı araştırma konuları açık |
| 1, gözlem | Envanter içe aktarma, Cacti aygıtları, smoke ve özet grafikleri, Thold uyarısı, Maint bastırma, sağlık sayfası | [M2](https://github.com/somethingwithproof/plugin_smokeping/milestone/2), [M3](https://github.com/somethingwithproof/plugin_smokeping/milestone/3), [M4](https://github.com/somethingwithproof/plugin_smokeping/milestone/4) | 1.0.0-rc.1 olarak yayımlandı; pilot uygulamalar açık |
| 2, yönetilen | Cacti'ye ait yapılandırma ad alanı, ICMP ve TCP yoklama profilleri, revizyonlar, doğrulama, yeniden yükleme, geri alma | [M5](https://github.com/somethingwithproof/plugin_smokeping/milestone/5) | Planlandı, başlanmadı |
| 3, uzak | Cacti uzak veri toplayıcılarında SmokePing, ölçüm konumları olarak ikincil düğümler | [M6](https://github.com/somethingwithproof/plugin_smokeping/milestone/6) | Planlandı, başlanmadı |

Kapsam dışı: eklentiye ait bir uyarı motoru, bir raporlama motoru, özel bir arka plan hizmeti veya ağ protokolü, yönetilen HTTP/DNS/servis denetimleri (bunları servcheck karşılar) ve SmokePing'in RRD dosyalarına yazma.

## Uyumluluk hedefleri

| Bileşen | Hedef |
|---|---|
| Cacti | 1.2.x serisinde 1.2.31 veya üzeri, çekirdek yaması gerekmez |
| PHP | 8.2 veya üzeri; desteklenmeyen bir çalışma zamanının bozuk bir Cacti arayüzü yerine ret yanıtı alması için `setup.php` 8.0 üzerinde ayrıştırılabilir kalır |
| SmokePing | 2.9.0, 2.8.2 ve 2.7.3 (yalnızca Ubuntu 22.04, 2027-06-01 tarihine kadar) |
| Thold, Maint | Değişiklik gerekmez |
| Seri | 20 ping ve 300 sn adımla kurulum başına 10.000 ([performans](../../performance.md#supported-series-count)) |

## Belgelendirme

| | |
|---|---|
| [Kurulum](../../install.md) | Gereksinimler, SmokePing veri dizininin salt okunur bağlanması, kurulum, kapsayıcılar, desteklenen sürümler |
| [İşletim](../../operations.md) | Her durumun anlamı ve kanıtları, sağlık denetimleri, girdiler başarısız olduğundaki davranış |
| [Cacti'ye hedef ekleme](../../provisioning.md) | Tek hedef, çoklu aygıtlar, yeniden bağlama, izinler |
| [Uyarılar](../../alerting.md) | Özet değerler, Thold süreleri, bayatlama, gecikmeli veriler, SmokePing uyarılarıyla örtüşme, Maint |
| [Yapılandırma biçimi](../../configuration-format.md) | YAML ve XML dışa ve içe aktarma |
| [Yedekleme ve geri yükleme](../../backup-restore.md) | Nelerin yedekleneceği ve geri yüklemenin nasıl devam edeceği |
| [Performans](../../performance.md) | 100, 1.000 ve 10.000 seride yerel ve sürüm çalıştırıcısı ölçümleri ve desteklenen seri sayısı: kurulum başına 10.000 |
| [Hata yeterliliği](../../failure-qualification.md) | Hata senaryoları ve her birinin nasıl test edildiği |
| [Sürüm yayımlama](../../RELEASING.md) | Sürüm yayımlama prosedürü: pull request, imzalı etiket, iş akışının yaptıkları, sonucun nasıl doğrulanacağı |
| [Sürüm arşivi](../../release.md) | Sürüm, değişiklik günlüğü ve sürüm arşivi |
| [Test](../../testing.md) | Test paketleri, CI'ın çalıştırdıkları ve gerçek bir SmokePing'e karşı canlı test paketi |

## Tasarım

- [docs/PLAN.md](../../PLAN.md) tam plandır: mimari, dayandığı üst kaynak gerçekleri (kaynaklarıyla birlikte), durum modeli, güvenlik sınırları, performans aritmetiği, test ve riskler.
- [docs/decisions/](../../decisions) hâlihazırda alınmış seçimler için mimari karar kayıtlarını barındırır.
- [docs/REQUIREMENTS.md](../../REQUIREMENTS.md) her gereksinim kimliğini ilgili konuyla eşleştirir.

```mermaid
flowchart LR
    SP[SmokePing arka plan hizmeti] --> RRD[(RRD dosyaları)]
    RRD -->|salt okunur| R[Bitişik Cacti yoklayıcısındaki okuyucu]
    RRD -->|salt okunur| G[Cacti grafik şablonları]
    R --> SS[Betik sunucusu özet veri kaynakları]
    SS -->|poller_output| T[Thold ve Maint]
    G --> P[Cacti grafik izinleri]
```

## Çalışmalar nasıl takip edilir

- **Kilometre taşları** plandaki kilometre taşlarını izler. Her birinin, alt konuları ve çıkış kanıtlarını listeleyen bir epik konusu vardır.
- **Konular** etiketlerle türlere ayrılır: `type: requirement`, `type: research`, `type: decision`, `type: epic`. Gereksinim konuları numaralandırılmış, test edilebilir gereksinimleri (`REQ-AREA-NNN`) ve kabul ölçütlerini belirtir.
- **Etiketler** ayrıca `area:`, `phase:`, `priority:` ve `status:` öneklerini taşır. Bkz. [CONTRIBUTING.md](../../../CONTRIBUTING.md#labels).
- [Proje panosu](https://github.com/users/somethingwithproof/projects/6) kilometre taşları genelindeki tüm konuları gösterir.

## Katkıda bulunma

[CONTRIBUTING.md](../../../CONTRIBUTING.md) dosyasını okuyun. Commit'ler DCO imzası gerektirir. Tasarım tartışmaları konular üzerinde yapılır; açık kararlar `type: decision` etiketini taşır.

## Güvenlik

Güvenlik açıklarını gizli olarak bildirin; bkz. [SECURITY.md](../../../SECURITY.md).

## Lisans

GNU Genel Kamu Lisansı v2.0, Cacti ve eklentileriyle aynı lisans. Bkz. [LICENSE](../../../LICENSE).
