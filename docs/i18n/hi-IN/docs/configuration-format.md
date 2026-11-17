<!-- Source: docs/configuration-format.md; SHA256: ec68c633a93e30aa23abef57fd66184cc2e8857c66333a82ce211fc72938f7c1 -->

<a id="configuration-file-format"></a>
# कॉन्फ़िगरेशन फ़ाइल प्रारूप

प्लगइन की अपनी कॉन्फ़िगरेशन एक फ़ाइल प्रारूप में आयात और निर्यात होती है, जिसके दो रूप हैं: YAML (डिफ़ॉल्ट) और XML। यह पृष्ठ स्कीमा संस्करण 1 परिभाषित करता है। डिज़ाइन के कारण [निर्णय 0008](../../../decisions/0008-yaml-and-xml-configuration-files.md) में हैं।

फ़ाइल में प्लगइन सेटिंग, प्लगइन द्वारा पढ़े गए SmokePing लक्ष्य (अवलोकित लक्ष्य), और हर लक्ष्य का मैप किया Cacti उपकरण होता है। डेटा स्रोत, ग्राफ़, RRD पथ या क्रेडेंशियल नहीं होते। Cacti ग्राफ़, डेटा और होस्ट टेम्पलेट अलग हैं और Cacti XML ही रहते हैं।

<a id="commands"></a>
## कमांड

```sh
php plugins/smokeping/cli/smokeping.php export                         # YAML to standard output
php plugins/smokeping/cli/smokeping.php export --format=xml --file=/var/backups/smokeping-config.xml
php plugins/smokeping/cli/smokeping.php import --file=smokeping-config.yaml           # check only
php plugins/smokeping/cli/smokeping.php import --file=smokeping-config.yaml --apply --confirm=<plan>
```

`export --file` केवल मालिक के पढ़ने योग्य नई फ़ाइल बनाता है और मौजूदा फ़ाइल बदलने से इनकार करता है। `--apply` के बिना `import` फ़ाइल पढ़ता और जाँचता है, हर प्रस्तावित बदलाव और योजना ID दिखाता है, कुछ नहीं लिखता। `--apply` के लिए उसी ID वाला `--confirm` चाहिए; यह एक ट्रांज़ैक्शन में लागू होता है और जाँच के बाद फ़ाइल या इंस्टॉलेशन बदल गया हो तो इनकार करता है। निकास स्थिति: 0 पूर्ण, 1 अस्वीकार या विफल, 2 फ़ाइल या योजना में त्रुटियाँ।

यही क्रियाएँ **Management > SmokePing Configuration** (SmokePing Admin क्षेत्र) में हैं: निर्यात डाउनलोड करें, जाँच के लिए फ़ाइल चुनें, सूचीबद्ध बदलाव देखें और लागू करें।

आयात केवल जोड़ता और अपडेट करता है। यह कभी लक्ष्य नहीं हटाता, मैपिंग दूसरे उपकरण पर नहीं ले जाता (Inventory पृष्ठ पर फिर जोड़ें), स्रोत, ग्राफ़ या RRD फ़ाइल नहीं बनाता और SmokePing डेटा डायरेक्टरी को पढ़ता या लिखता नहीं। अनइंस्टॉल कॉन्फ़िगरेशन का YAML निर्यात **Backup Directory** सेटिंग में, या खाली होने पर Cacti डायरेक्टरी के `cache/smokeping/` में लिखता है। फ़ाइल का नाम यादृच्छिक और मोड `0600` है; डिफ़ॉल्ट डायरेक्टरी में सबको रोकने वाला `.htaccess` और कुछ न परोसने वाला `index.php` है। Backup Directory मौजूद न हो या लिखने योग्य न हो तो निर्यात नहीं लिखा जाता (प्लगइन डिफ़ॉल्ट पर नहीं लौटता); अनइंस्टॉल आगे बढ़कर बताता है कि फ़ाइल कहाँ गई या कोई फ़ाइल नहीं लिखी गई। वेब रूट के बाहर डायरेक्टरी चुनें।

<a id="schema-version-1"></a>
## स्कीमा संस्करण 1

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

पहचान। लक्ष्य `poller_id` और `path` से पहचाना जाता है। उपकरण `hostname` और `description` से; यह जोड़ी किसी उपकरण में न मिले या एक से अधिक में मिले तो जाँच मैपिंग अस्वीकार करती है। आयात पाठ के आस-पास की रिक्त जगह हटाता है क्योंकि प्लगइन हर संग्रहित मान ऐसे ही पढ़ता है।

आरक्षित कुंजियाँ। `managed_targets` और `probe_profiles` भविष्य के प्रबंधित मोड संस्करण के लिए आरक्षित हैं। संस्करण 1 कुंजी का नाम बताने वाली त्रुटि से इन्हें अस्वीकार करता है ताकि उस संस्करण की फ़ाइल कभी आधी लागू न हो। आगे का संस्करण इन्हें स्कीमा में जोड़ेगा; `schema_version` तभी बढ़ेगा जब यहाँ मौजूद किसी कुंजी का अर्थ बदले।

अपडेट। पहले से मौजूद लक्ष्य के लिए आयात केवल `host`, `probe`, `menu`, `title`, `slaves`, `nomasterpoll`, `step`, `pings` और `admin_state` बदलता है। पहले छह फ़ील्ड का स्रोत SmokePing कॉन्फ़िगरेशन ही रहता है: अगली इन्वेंटरी उन्हें वापस लाती है। इसलिए आयात `admin_state` बहाल करने और नया इंस्टॉलेशन तैयार करने के लिए इस्तेमाल करें, लक्ष्य संपादित करने के लिए नहीं।

सेटिंग मान बताए गए प्रकार के होने चाहिए। पाठ में नियंत्रण वर्ण नहीं हो सकते।

केवल संदर्भ सेटिंग। फ़ाइल या डायरेक्टरी बताने वाली सेटिंग (ऊपर `reference_settings` की सभी) आयात से कभी नहीं लिखी जाती: फ़ाइल को प्लगइन की पढ़ने-लिखने की जगह बदलने नहीं देना चाहिए। स्कीमा इन्हें प्रकार से चिह्नित करता है; ऐसी सेटिंग `settings` में रखने वाली फ़ाइल अस्वीकार होती है। `reference_settings` में उनका रूप जाँचा जाता है (पूर्ण पथ, या जहाँ मान्य हो खाली), इंस्टॉलेशन से तुलना होती है और अलग होने पर योजना में `not applied, change on the Settings page` दिखता है। इन्हें Settings पृष्ठ पर बदलें। 1.0.0-rc.1 के निर्यात वहाँ `smokeping_perl_binary` और `smokeping_lib_dir` भी दिखाते हैं; ये सेटिंग अब नहीं हैं और आयात इन्हें छोड़ता है।

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

XML का ऊपर की YAML कुंजियों से एक-से-एक मेल है। बूलियन `true` या `false` हैं। रीडर कोई भी DOCTYPE या एंटिटी घोषणा अस्वीकार करता है, बाहरी संसाधन नहीं लाता और केवल ये तत्व स्वीकार करता है।

<a id="yaml-subset"></a>
## YAML उपसमुच्चय

रीडर ब्लॉक मैप, ब्लॉक सूची, साधारण तथा एकल/दोहरे उद्धरण वाले स्केलर, टिप्पणियाँ और `|` व `|-` शाब्दिक ब्लॉक स्वीकार करता है, शुरू में एक वैकल्पिक `---` सहित। दोहरे उद्धरण वाले स्केलर `\\ \" \/ \n \t \r \0 \b \f \e \xHH \uHHHH` एस्केप स्वीकार करते हैं; एकल उद्धरण वाले उद्धरण को दोहराते हैं (`''`)। उद्धृत स्केलर अपनी ही पंक्ति में बंद होता है।

यह पंक्ति संख्या के साथ अस्वीकार करता है: एंकर (`&x`), उपनाम (`*x`), टैग (`!x`), फ़्लो संग्रह (`[a, b]`, `{a: b}`), फ़ोल्डेड ब्लॉक (`>`), कई दस्तावेज़, निर्देश, मर्ज कुंजियाँ (`<<`), जटिल कुंजियाँ (`?`), दोहराई कुंजियाँ, इंडेंट के लिए टैब, बहुपंक्ति साधारण स्केलर और 8 स्तर से गहरा नेस्टिंग। ऐसे साधारण स्केलर भी अस्वीकार होते हैं जिन्हें दूसरा YAML संस्करण पाठ के बजाय कुछ और पढ़े: `yes`, `no`, `on`, `off`, `y`, `n`, शुरू में शून्य वाले अंक, दशमलव, हेक्स, तिथियाँ और `1:30` जैसे समय। उन्हें उद्धृत करें (`'yes'`)। `true`, `false`, `null`, `~` और साधारण पूर्णांक अपना अर्थ रखते हैं।

निर्यातक हर ऐसी स्ट्रिंग उद्धृत करता है जिसे रीडर गलत पार्स कर सकता हो, जैसे कोलन या `#`, आरंभ या अंत में रिक्त जगह, या ऐसा पहला वर्ण जिसे YAML संकेतक मानता हो।

<a id="limits"></a>
## सीमाएँ

दोनों प्रारूपों में फ़ाइल अधिकतम 8 MiB और नेस्टिंग 8 स्तर है, तथा एक पंक्ति अधिकतम 16 KiB। फ़ाइल पढ़ने से पहले आकार जाँचा जाता है।
