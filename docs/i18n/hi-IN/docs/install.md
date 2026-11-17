<!-- Source: docs/install.md; SHA256: 0d075ea04065badbbe1a97434609b979a10cb760b6f483e6cb7f8bc4768a214e -->

<a id="installing-the-smokeping-plugin"></a>
# SmokePing प्लगइन स्थापित करना

यह मार्गदर्शिका Cacti 1.2 होस्ट और SmokePing इंस्टॉलेशन को शुरुआत से Cacti ग्राफ़ तक ले जाती है। इसमें प्लगइन, डेटा डायरेक्टरी की केवल-पठन पहुँच (सबसे अधिक गलती वाली जगह), सत्यापन, कंटेनर और समर्थन हैं। रोज़ का संचालन [संचालन](operations.md), अलर्ट [अलर्ट](alerting.md), बैकअप [बैकअप और बहाली](backup-restore.md) में हैं।

<a id="what-is-supported"></a>
## क्या समर्थित है

| घटक | समर्थित | यहाँ परीक्षित |
|---|---|---|
| Cacti | 1.2.31 और वर्तमान 1.2.x (1.2.32 स्रोत पथ जाँच बदलता है; नीचे माउंट देखें) | 1.2.31 रिलीज़ और 1.2.32 विकास प्रति |
| PHP | 8.2 या बाद का। 8.0/8.1 में स्थापना अस्वीकार, Cacti चालू रहता है | एकीकरण और एंड-टू-एंड के लिए 8.2.35; प्रवेश बिंदुओं का 8.0 सिंटैक्स लिंट |
| SmokePing | 2.9.0, 2.8.2, 2.7.3 (केवल Ubuntu 22.04, 2027-06-01 तक) | तीनों का कॉन्फ़िगरेशन पार्सर, मूल पार्सर से 2.7.3 (Ubuntu 22.04), 2.8.2 (Debian 13), 2.9.0 (Fedora 45) पर तुलना; तीनों की ग्राफ़ समानता |
| rrdtool | Cacti का संस्करण; रीडर `path_rrdtool` चलाता है | 1.7.2 |
| Thold, Maint | बदलाव नहीं चाहिए | प्लगइन कार्यक्षेत्र के संस्करण |
| शृंखला संख्या | प्रति इंस्टॉलेशन 10,000, 20 पिंग और 300 सेकंड चरण, चार समर्पित vCPU व 8 GB ([प्रदर्शन](performance.md#supported-series-count)) | रिलीज़ रनर पर 100, 1,000 और 10,000 |

«शृंखला» एक स्थान (मास्टर या स्लेव) पर एक लक्ष्य है। वितरण समर्थन विक्रेताओं की पूर्ण समर्थन अवधि के अनुसार है; [वितरण तालिका](../../../research/distribution-matrix/README.md) देखें। Debian 13, Ubuntu 22.04 से 26.10, EL 9, Fedora 43 से 45, Alpine 3.22 से 3.24 में SmokePing पैकेज है। **EL 10 और openSUSE Leap 16 में SmokePing पैकेज नहीं**, इसलिए वहाँ प्लगइन उतना ही चलता है जितना स्रोत से स्थापित SmokePing; यह मार्गदर्शिका उसे नहीं बताती।

<a id="how-the-pieces-fit"></a>
## हिस्से कैसे जुड़ते हैं

SmokePing RRD लिखता है। Cacti ग्राफ़ बनाता और प्लगइन नवीनतम मान पढ़ता है; दोनों लिखते नहीं। Cacti 1.2.32 और बाद RRA के बाहर स्रोत पथ अस्वीकार करते हैं, इसलिए प्लगइन **Cacti RRA पथ के अंदर केवल-पठन माउंट** से डेटा दिखाता है, डिफ़ॉल्ट `<rra_path>/smokeping` ([निर्णय 0007](../../../decisions/0007-read-only-mount-under-rra-path.md))। यही फ़ाइलों की मुख्य सुरक्षा है: Cacti सफ़ाई इसके जरिए हटा या नाम नहीं बदल सकती।

रीडर चलाने वाले Cacti पोलर को SmokePing होस्ट पर दो चीजें पढ़नी चाहिए:

1. कॉन्फ़िगरेशन (`/etc/smokeping/config`) और शामिल फ़ाइलें, प्लगइन के अपने PHP पार्सर से। शामिल फ़ाइलें उसी डायरेक्टरी या नीचे हों; अन्य अस्वीकार। प्लगइन Perl नहीं चलाता और SmokePing Perl लाइब्रेरी नहीं चाहिए।
2. माउंट से डेटा डायरेक्टरी।

अलग होस्ट हों तो डेटा केवल-पठन NFS से साझा करके SmokePing पोलर होस्ट पर माउंट करें; [संचालन](operations.md#topologies) देखें। Cacti दूरस्थ संग्रहकर्ता पर SmokePing बाद का चरण है, इस रिलीज़ में नहीं।

<a id="1-install-smokeping-if-it-is-not-installed"></a>
## 1. SmokePing स्थापित करें (यदि नहीं है)

Debian 13 और Ubuntu पर:

<!-- verify:install-smokeping-deb -->
```sh
apt-get update
DEBIAN_FRONTEND=noninteractive apt-get install -y smokeping
```

EL 9 पर (पैकेज EPEL में है और CodeReady Builder चाहिए):

<!-- verify:install-smokeping-el -->
```sh
dnf install -y epel-release
dnf config-manager --set-enabled crb
dnf install -y smokeping
```

सामान्य रूप से कॉन्फ़िगर कर `systemctl enable --now smokeping` चलाएँ। डेटा डायरेक्टरी Debian/Ubuntu/Alpine पर `/var/lib/smokeping`, EL/Fedora पर `/var/lib/smokeping/rrd` है। नीचे `DATADIR` वही है (`*** Database ***` अनुभाग का `datadir`) और `RRA` Cacti RRA पथ (Console > Configuration > Settings > Paths, «RRA Path»; उदाहरण में `/var/www/html/cacti/rra`)।

**डेटा डायरेक्टरी में और क्या है।** Debian स्लेव कैश `DATADIR/__cgi/<section>/` (SmokePing `dyndir`) में रखता है और अनुभाग डायरेक्टरी खुद नहीं बनाता, इसलिए अभी तक रिपोर्ट न करने वाला स्लेव इसे खाली या अनुपस्थित छोड़ता है; Alpine चित्र कैश `DATADIR/images` में, और sorter कैश `DATADIR/__sortercache` में हैं। प्लगइन सब अनदेखा करता है: डायरेक्टरी सूची कभी नहीं पढ़ता, केवल कॉन्फ़िगरेशन वाली RRD (`<section>/<target>.rrd`, हर स्लेव का `<target>~<slave>.rrd`) पढ़ता है। न ये डायरेक्टरियाँ, न बिना लक्ष्य की RRD (जैसे `Local/LocalMachine.rrd`) पढ़ी, सूचीबद्ध या त्रुटि बताई जाती है। Debian 13 मास्टर और दो स्लेव पर जाँचा गया ([परीक्षण](../../../testing.md#the-live-suite))।

<a id="2-let-the-cacti-poller-read-smokeping"></a>
## 2. Cacti पोलर को SmokePing पढ़ने दें

पैकेज वितरणों में RRD सभी के पढ़ने योग्य और ऊपर की डायरेक्टरियाँ सभी के गुजरने योग्य हैं, इसलिए पोलर उपयोगकर्ता को **किसी समूह सदस्यता की जरूरत नहीं**। कॉन्फ़िगरेशन Debian/Ubuntu/Alpine पर सभी पढ़ सकते हैं; EL/Fedora में `0640 root:apache`, केवल `apache` और root।

पोलर उपयोगकर्ता बनकर जाँचें (`www-data` Debian/Ubuntu, `apache` EL/Fedora):

<!-- verify:poller-access -->
```sh
runuser -u "$POLLER_USER" -- test -r "$DATADIR" && echo "datadir readable"
runuser -u "$POLLER_USER" -- test -r /etc/smokeping/config && echo "config readable"
runuser -u "$POLLER_USER" -- ls "$DATADIR" > /dev/null && echo "datadir listable"
```

हर पंक्ति को प्रिंट करना चाहिए और पैकेज अनुमतियों में होता है: SmokePing होस्ट पर समूह या अन्य सेटअप नहीं चाहिए। तभी विफल जब अनुमतियाँ पैकेज से सख्त हों (जैसे `datadir` `0750 smokeping:smokeping`) या EL/Fedora में पोलर `apache` के अलावा हो, जहाँ कॉन्फ़िगरेशन `0640 root:apache` है। तब केवल उस उपयोगकर्ता को पढ़ने की पहुँच दें, जैसे कॉन्फ़िगरेशन डायरेक्टरी और `DATADIR` पर ACL (`setfacl -R -m u:$POLLER_USER:rX`); Cacti उपयोगकर्ता को SmokePing फ़ाइलें लिखने योग्य कभी न बनाएँ।

<a id="3-mount-the-data-directory-read-only-inside-cactis-rra-path"></a>
## 3. डेटा डायरेक्टरी Cacti RRA के अंदर केवल-पठन माउंट करें

दो में से एक तरीका चुनें। दोनों bind mount बनाते हैं और एक बार root चाहिए।

<a id="option-a-an-etcfstab-line"></a>
### विकल्प A: `/etc/fstab` पंक्ति

<!-- verify:mount-fstab -->
```sh
mkdir -p "$RRA/smokeping"
echo "$DATADIR  $RRA/smokeping  none  bind,ro  0 0" >> /etc/fstab
mount "$RRA/smokeping"
```

माउंट तुरंत और हर बूट पर लागू होता है। साधारण `mount --bind` से जाँचें तो remount जोड़ें क्योंकि पुराना `util-linux` पहले bind पर `ro` अनदेखा करता है: `mount -o remount,bind,ro "$RRA/smokeping"`।

<a id="option-b-a-systemd-mount-unit"></a>
### विकल्प B: systemd `.mount` यूनिट

फ़ाइल नाम माउंट पथ में `/` की जगह `-` होना चाहिए; systemd से निकालें:

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

माउंट पर निर्भर सेवाएँ उससे पहले शुरू हो सकती हों तो यूनिट में `RequiresMountsFor=` दें, जैसे `php-fpm.service` ड्रॉप-इन में `RequiresMountsFor=/var/www/html/cacti/rra/smokeping`।

दूसरे होस्ट के लिए bind की जगह केवल-पठन NFS (`smokeping-host:/var/lib/smokeping  $RRA/smokeping  nfs  ro,nfsvers=4.2  0 0`) दें और वहाँ से डायरेक्टरी केवल-पठन निर्यात करें।

<a id="verify-the-mount"></a>
### माउंट सत्यापित करें

<!-- verify:mount-verify -->
```sh
findmnt -no TARGET,SOURCE,OPTIONS "$RRA/smokeping"
findmnt -no OPTIONS "$RRA/smokeping" | tr ',' '\n' | grep -x ro
touch "$RRA/smokeping/.cacti-write-test" && echo "WRITABLE: fix the mount before continuing" || echo "read-only, as required"
ls "$RRA/smokeping" | head
```

`ro` दिखना चाहिए, `touch` को `Read-only file system` से विफल होना चाहिए (root भी), और सूची में SmokePing अनुभाग दिखने चाहिए। Health `/proc/self/mountinfo` से फिर जाँचकर लिखने योग्य/गायब माउंट या पथ के सिम्बॉलिक लिंक को **त्रुटि** बताता है। सिम्बॉलिक लिंक माउंट का विकल्प नहीं: Cacti 1.2.32 और बाद उससे गुजरते स्रोत पथ अस्वीकार करते हैं।

<a id="4-install-the-plugin"></a>
## 4. प्लगइन स्थापित करें

रिलीज़ आर्काइव से ([पैकेजिंग](../../../release.md) देखें):

```sh
tar -xzf smokeping-0.2.0.tar.gz -C /var/www/html/cacti/plugins
chown -R root:root /var/www/html/cacti/plugins/smokeping
cd /var/www/html/cacti
sudo -u www-data php cli/plugin_manage.php --plugin=smokeping --install --enable --allperms
```

डायरेक्टरी नाम `smokeping` होना चाहिए। अंतिम कमांड Cacti फ़ाइलों के मालिक (`apache` EL पर) से चलाएँ। `--allperms` कंसोल व्यवस्थापक को तीनों क्षेत्र देता है; दूसरे उपयोगकर्ताओं को User Management से **SmokePing View**, **SmokePing Operator** या **SmokePing Admin** दें। Operator में View और Admin में Operator पहुँच शामिल है। प्लगइन तालिकाएँ तथा ग्राफ़/होस्ट टेम्पलेट लगाता है; टेम्पलेट आयात विफल हो तो Health में बताकर काम जारी रखता है और कारण सुधारने पर **Retry Template Import** दोहराता है।

8.2 से पुराने PHP में आवश्यक संस्करण बताते हुए स्थापना अस्वीकार होती है, बाकी Cacti अप्रभावित रहता है।

<a id="5-configure-and-confirm"></a>
## 5. कॉन्फ़िगर और पुष्टि करें

**Console > Configuration > Settings > SmokePing** खोलकर जाँचें:

| सेटिंग | अर्थ |
|---|---|
| SmokePing Poller | SmokePing होस्ट पर या साझा फ़ाइल सिस्टम वाला Cacti पोलर। केवल यही डेटा पढ़ता है। |
| SmokePing Configuration File | `/etc/smokeping/config`, यदि आपने न बदला हो |
| SmokePing Data Directory | SmokePing को दिखता `DATADIR` |
| Cacti Mount of the Data Directory | चरण 3 का माउंट; डिफ़ॉल्ट `<rra_path>/smokeping` |
| Loss Colors | Automatic, लेकिन रीडर `Smokeping.pm` न देख सके और संस्करण 2.7 हो तो SmokePing 2.7। [हानि रंग](provisioning.md#loss-colors) देखें |

फिर SmokePing पोलर सर्वर पर **SmokePing Health** खोलें। कॉन्फ़िगरेशन पंक्ति तुरंत पढ़कर लक्ष्य संख्या या फ़ाइल/पंक्ति सहित पहली त्रुटि दिखाती है। अगले पोलर चक्र के बाद PHP, कॉन्फ़िगरेशन, रीडर, इन्वेंटरी, माउंट और टेम्पलेट सभी OK होने चाहिए। प्रतीक्षा के बिना पहला रीडर और इन्वेंटरी चलाएँ:

```sh
sudo -u www-data php plugins/smokeping/poller_smokeping.php --force --inventory
```

**SmokePing Inventory** अब सभी लक्ष्य दिखाता है। अभी ग्राफ़ नहीं; Cacti में जोड़ने के लिए [प्रोविज़निंग](provisioning.md) देखें।

<a id="languages"></a>
## भाषाएँ

प्लगइन में स्पेन का स्पैनिश (`es-ES`), फ़्रेंच (`fr-FR`), जर्मन (`de-DE`), सरल चीनी (`zh-CN`), हिंदी (`hi-IN`), अरबी (`ar-SA`) और जापानी (`ja-JP`) हैं। Language Support चालू (Configuration > Settings > General > Language Support) करके उपयोगकर्ता भाषा चुने तो Cacti दिखाता है। लैटिन अमेरिकी स्पैनिश (`es-419`) भी है, पर Cacti 1.2 में वह लोकेल चुनने को नहीं, इसलिए Cacti के जोड़ने पर ही प्रभावी होगा; तब तक स्पैनिश (`es-ES`) चुनें।

<a id="containers"></a>
## कंटेनर

रीडर वहाँ चलाएँ जहाँ शुरुआत की दोनों चीजें दिखें।

- **कंटेनर में Cacti, होस्ट पर SmokePing।** डेटा RRA पथ पर केवल-पठन और कॉन्फ़िगरेशन भी केवल-पठन माउंट करें:

  ```sh
  docker run -d --name cacti \
    -v /var/lib/smokeping:/var/www/html/cacti/rra/smokeping:ro \
    -v /etc/smokeping:/etc/smokeping:ro \
    my-cacti-image
  ```

  डेटा माउंट का बायाँ भाग होस्ट के `DATADIR` से बदलें। इमेज को SmokePing से कुछ नहीं चाहिए: न Perl, न `smokeping` पैकेज। कंटेनर PHP 8.2 या बाद होना चाहिए। Health माउंट का `:ro` जाँचता है; बिना इसके त्रुटि।
- **Docker Compose।** यही `volumes:` में `read_only: true` और `type: bind` से:

  ```yaml
  services:
    cacti:
      volumes:
        - type: bind
          source: /var/lib/smokeping
          target: /var/www/html/cacti/rra/smokeping
          read_only: true
  ```

- **Kubernetes।** `volumeMounts` में `readOnly: true`, पीछे hostPath या NFS/CSI वॉल्यूम जहाँ SmokePing pod लिखता है।
- **कंटेनर में SmokePing।** `datadir` Cacti होस्ट से साझा वॉल्यूम पर रखें और ऊपर की तरह केवल-पठन माउंट करें। प्लगइन को SmokePing कंटेनर में प्रवेश नहीं चाहिए।

<a id="cactis-rrd-cleaner-will-list-smokeping-files"></a>
## Cacti RRD क्लीनर SmokePing फ़ाइलें दिखाएगा

क्लीनर (**Utilities > RRD Cleaner**) पूरा RRA पथ देखता है, इसलिए बिना Cacti स्रोत की SmokePing RRD, स्लेव `target~slave.rrd` सहित, अनुपयोगी दिखाकर हटाने का विकल्प देता है। न चुनें। केवल-पठन माउंट से हटाना/संग्रहित करना विफल, Cacti चेतावनी लॉग और कतार प्रविष्टि हटाता है; फ़ाइलें बचती हैं, लेकिन चेतावनियाँ अनावश्यक हैं और लिखने योग्य माउंट डेटा खो देता। प्लगइन purge से पहले पहचानी कतार प्रविष्टियाँ हटाता है (हर पोलर रन शुरू में), इसलिए चेतावनियाँ कम होती हैं। क्लीनर में बहिष्करण सूची नहीं; चयन से पहले सूची पढ़ें।

<a id="upgrading-and-removing"></a>
## अपग्रेड और हटाना

नई रिलीज़ से प्लगइन डायरेक्टरी बदलकर Plugins पृष्ठ खोलें या अगला पोलर चक्र आने दें; तालिकाएँ और टेम्पलेट माइग्रेट करके सभी चरण सफल होने पर ही संस्करण दर्ज करता है, इसलिए बाधित अपग्रेड अगली बार पूरा होता है। निष्क्रिय करने से काम रुकते हैं, SmokePing फ़ाइलें नहीं बदलतीं। **अनइंस्टॉल से पहले स्वयं कॉन्फ़िगरेशन निर्यात करें और सत्यापित करें कि बैकअप पढ़ने योग्य और पूर्ण है।** अनइंस्टॉल प्लगइन कॉन्फ़िगरेशन का YAML निर्यात Backup Directory या Cacti के `cache/smokeping/` में लिखने का प्रयास करता है (यादृच्छिक नाम, मोड `0600`, सफलता पर पथ दिखाया जाता है)। निर्यात विफल होने पर चेतावनी लॉग में दर्ज होती है और दिखाई जाती है, लेकिन अनइंस्टॉल जारी रहता है। **Keep Plugin Tables on Uninstall** सेट न हो तो प्लगइन की तालिकाएँ हटती हैं; SmokePing फ़ाइलें और कॉन्फ़िगरेशन कभी नहीं बदलते।
