<!-- Source: docs/install.md; SHA256: 0d075ea04065badbbe1a97434609b979a10cb760b6f483e6cb7f8bc4768a214e -->

<a id="installing-the-smokeping-plugin"></a>
# Instalar el complemento SmokePing

Esta guía lleva un servidor Cacti 1.2 y una instalación SmokePing desde cero hasta gráficos en Cacti. Cubre el complemento, el acceso de solo lectura al directorio de datos (la parte que más suele fallar), su verificación, contenedores y soporte. La operación diaria está en [operaciones](operations.md), las alertas en [alertas](alerting.md) y las copias en [copia y restauración](backup-restore.md).

<a id="what-is-supported"></a>
## Qué está admitido

| Componente | Admitido | Probado aquí |
|---|---|---|
| Cacti | 1.2.31 y rama 1.2.x actual (1.2.32 cambia la comprobación de rutas de fuentes; véase el montaje) | Versión 1.2.31 y copia de desarrollo 1.2.32 |
| PHP | 8.2 o posterior. En 8.0 y 8.1 rechaza instalarse y Cacti sigue funcionando | 8.2.35 para integración y extremo a extremo; sintaxis de puntos de entrada en 8.0 |
| SmokePing | 2.9.0, 2.8.2 y 2.7.3 (solo Ubuntu 22.04, hasta 2027-06-01) | Los tres para el analizador, comparados con el original en 2.7.3 (Ubuntu 22.04), 2.8.2 (Debian 13) y 2.9.0 (Fedora 45); los tres para fidelidad de gráficos |
| rrdtool | La versión de Cacti; el lector ejecuta `path_rrdtool` | 1.7.2 |
| Thold, Maint | Sin cambios necesarios | Versiones del espacio de trabajo de complementos |
| Series | 10.000 por instalación con 20 pings y paso de 300 s, cuatro vCPU dedicadas y 8 GB ([rendimiento](performance.md#supported-series-count)) | 100, 1.000 y 10.000 en el ejecutor de publicaciones |

Una «serie» es un objetivo en una ubicación (maestro o esclavo). El soporte de distribuciones sigue sus periodos de soporte completo; véase la [matriz](../../../research/distribution-matrix/README.md). Debian 13, Ubuntu 22.04 a 26.10, EL 9, Fedora 43 a 45 y Alpine 3.22 a 3.24 empaquetan SmokePing. **EL 10 y openSUSE Leap 16 no tienen paquete SmokePing**; allí solo funciona en la medida en que funcione una instalación desde código fuente, que no cubre esta guía.

<a id="how-the-pieces-fit"></a>
## Cómo encajan las partes

SmokePing escribe RRD. Cacti los grafica y el complemento lee sus últimos valores; ninguno los escribe. Cacti 1.2.32 y posteriores rechazan rutas fuera de RRA, por lo que se accede al directorio SmokePing mediante un **montaje de solo lectura dentro de la ruta RRA de Cacti**, por defecto `<rra_path>/smokeping` ([decisión 0007](../../../decisions/0007-read-only-mount-under-rra-path.md)). Este montaje protege también los archivos: la limpieza Cacti no puede eliminarlos ni renombrarlos a través de él.

El sondeador Cacti del lector debe poder leer dos cosas del servidor SmokePing:

1. La configuración (`/etc/smokeping/config`) y archivos incluidos, mediante el analizador PHP propio del complemento. Los incluidos deben estar en su directorio o debajo; se rechaza cualquier otro. No ejecuta Perl ni necesita la biblioteca Perl de SmokePing.
2. El directorio de datos mediante el montaje.

Si están en servidores distintos, comparta los datos en solo lectura (NFS) y móntelos donde corre el sondeador SmokePing; véase [operaciones](operations.md#topologies). SmokePing en un recolector remoto Cacti es una fase posterior no admitida aquí.

<a id="1-install-smokeping-if-it-is-not-installed"></a>
## 1. Instalar SmokePing (si falta)

En Debian 13 y Ubuntu:

<!-- verify:install-smokeping-deb -->
```sh
apt-get update
DEBIAN_FRONTEND=noninteractive apt-get install -y smokeping
```

En EL 9 (el paquete está en EPEL y necesita CodeReady Builder):

<!-- verify:install-smokeping-el -->
```sh
dnf install -y epel-release
dnf config-manager --set-enabled crb
dnf install -y smokeping
```

Configure SmokePing como de costumbre e inícielo con `systemctl enable --now smokeping`. El directorio de datos es `/var/lib/smokeping` en Debian, Ubuntu y Alpine y `/var/lib/smokeping/rrd` en EL y Fedora. Aquí `DATADIR` es ese directorio (`datadir` de `*** Database ***`) y `RRA` es la ruta RRA Cacti (Console > Configuration > Settings > Paths, «RRA Path»; `/var/www/html/cacti/rra` en ejemplos).

**Otros contenidos del directorio.** Debian conserva cachés de esclavos en `DATADIR/__cgi/<section>/` (`dyndir` de SmokePing) y no crea las secciones por sí mismo, por lo que un esclavo que no ha informado deja `__cgi/<section>/` vacío o ausente; Alpine conserva imágenes en `DATADIR/images` y las cachés de ordenación de SmokePing en `DATADIR/__sortercache`. El complemento ignora todo ello: nunca enumera el directorio y solo lee los RRD indicados por la configuración (`<section>/<target>.rrd` y `<target>~<slave>.rrd` por esclavo). Ni esos directorios ni un RRD sin objetivo (como `Local/LocalMachine.rrd`) se leen, enumeran o notifican como error. Se comprobó con un maestro Debian 13 y dos esclavos ([pruebas](../../../testing.md#the-live-suite)).

<a id="2-let-the-cacti-poller-read-smokeping"></a>
## 2. Permitir al sondeador Cacti leer SmokePing

En las distribuciones empaquetadas, cualquiera puede leer los RRD y atravesar sus directorios superiores: el usuario del sondeador **no necesita pertenecer a ningún grupo**. La configuración es legible por todos en Debian, Ubuntu y Alpine y `0640 root:apache` en EL y Fedora, donde solo `apache` y root la leen.

Compruebe como usuario del sondeador (`www-data` en Debian/Ubuntu, `apache` en EL/Fedora):

<!-- verify:poller-access -->
```sh
runuser -u "$POLLER_USER" -- test -r "$DATADIR" && echo "datadir readable"
runuser -u "$POLLER_USER" -- test -r /etc/smokeping/config && echo "config readable"
runuser -u "$POLLER_USER" -- ls "$DATADIR" > /dev/null && echo "datadir listable"
```

Cada línea debe imprimir, como ocurre con permisos de paquete: no hacen falta grupos ni otros ajustes en SmokePing. Solo falla con permisos más estrictos (por ejemplo `datadir` como `0750 smokeping:smokeping`) o usuario distinto de `apache` en EL/Fedora, cuya configuración es `0640 root:apache`. Dé acceso de lectura solo a ese usuario, por ejemplo mediante ACL (`setfacl -R -m u:$POLLER_USER:rX` en el directorio de configuración y `DATADIR`), y nunca permita que el usuario Cacti escriba archivos SmokePing.

<a id="3-mount-the-data-directory-read-only-inside-cactis-rra-path"></a>
## 3. Montar los datos en solo lectura dentro de RRA

Elija una de las dos formas. Ambas crean un montaje enlazado que necesita root una vez.

<a id="option-a-an-etcfstab-line"></a>
### Opción A: línea en `/etc/fstab`

<!-- verify:mount-fstab -->
```sh
mkdir -p "$RRA/smokeping"
echo "$DATADIR  $RRA/smokeping  none  bind,ro  0 0" >> /etc/fstab
mount "$RRA/smokeping"
```

Se aplica de inmediato y en cada arranque. Si prueba con `mount --bind`, añada un remontaje porque `util-linux` antiguo ignora `ro` en el primer enlace: `mount -o remount,bind,ro "$RRA/smokeping"`.

<a id="option-b-a-systemd-mount-unit"></a>
### Opción B: unidad systemd `.mount`

Su nombre debe ser la ruta del montaje con `/` convertido en `-`; deje que systemd lo calcule:

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

Use `RequiresMountsFor=` en unidades que necesitan el montaje si pueden arrancar antes, por ejemplo `RequiresMountsFor=/var/www/html/cacti/rra/smokeping` en un complemento de `php-fpm.service`.

Para otro servidor, sustituya el enlace por NFS de solo lectura (`smokeping-host:/var/lib/smokeping  $RRA/smokeping  nfs  ro,nfsvers=4.2  0 0`) y exporte el directorio en solo lectura desde aquel servidor.

<a id="verify-the-mount"></a>
### Verificar el montaje

<!-- verify:mount-verify -->
```sh
findmnt -no TARGET,SOURCE,OPTIONS "$RRA/smokeping"
findmnt -no OPTIONS "$RRA/smokeping" | tr ',' '\n' | grep -x ro
touch "$RRA/smokeping/.cacti-write-test" && echo "WRITABLE: fix the mount before continuing" || echo "read-only, as required"
ls "$RRA/smokeping" | head
```

Debe aparecer `ro`, `touch` debe fallar con `Read-only file system` (incluso como root) y la lista mostrar secciones SmokePing. Health repite la comprobación desde `/proc/self/mountinfo` y notifica como **error** un montaje escribible, ausente o un enlace simbólico en la ruta. Un enlace simbólico no sustituye al montaje: Cacti 1.2.32 y posteriores rechazan rutas que lo atraviesen.

<a id="4-install-the-plugin"></a>
## 4. Instalar el complemento

Desde un archivo de publicación (véase [empaquetado](../../../release.md)):

```sh
tar -xzf smokeping-0.2.0.tar.gz -C /var/www/html/cacti/plugins
chown -R root:root /var/www/html/cacti/plugins/smokeping
cd /var/www/html/cacti
sudo -u www-data php cli/plugin_manage.php --plugin=smokeping --install --enable --allperms
```

El directorio debe llamarse `smokeping`. Ejecute el último comando como propietario de los archivos Cacti (`apache` en EL). `--allperms` concede al administrador de consola los tres ámbitos; otros usuarios reciben **SmokePing View**, **SmokePing Operator** o **SmokePing Admin** desde User Management. Operator incluye acceso View, y Admin incluye acceso Operator. El complemento instala tablas y plantillas de gráficos y hosts; si falla importar plantillas, lo muestra en Health y sigue funcionando. **Retry Template Import** permite repetir después de corregir la causa.

PHP anterior a 8.2 rechaza instalar con un mensaje que indica la versión requerida; el resto de Cacti no se ve afectado.

<a id="5-configure-and-confirm"></a>
## 5. Configurar y confirmar

Abra **Console > Configuration > Settings > SmokePing** y compruebe:

| Ajuste | Significado |
|---|---|
| SmokePing Poller | Sondeador Cacti en el servidor SmokePing o que comparte su sistema de archivos. Solo él lee los datos. |
| SmokePing Configuration File | `/etc/smokeping/config`, salvo que lo haya movido |
| SmokePing Data Directory | `DATADIR`, como lo ve SmokePing |
| Cacti Mount of the Data Directory | Montaje del paso 3; predeterminado `<rra_path>/smokeping` |
| Loss Colors | Automatic, salvo SmokePing 2.7 cuyo `Smokeping.pm` no sea visible al lector: entonces SmokePing 2.7. Véase [colores](provisioning.md#loss-colors) |

Abra **SmokePing Health** en el servidor del sondeador. La fila de configuración lee inmediatamente el archivo y muestra el número de objetivos o el primer error con archivo y línea. Tras el próximo ciclo, todas las filas deberían ser OK: PHP, configuración, lector, inventario, montaje y plantillas. Para ejecutar lector e inventario sin esperar:

```sh
sudo -u www-data php plugins/smokeping/poller_smokeping.php --force --inventory
```

**SmokePing Inventory** enumera ya todos los objetivos. Aún no hay gráficos; consulte [aprovisionamiento](provisioning.md).

<a id="languages"></a>
## Idiomas

El complemento incluye español de España (`es-ES`), francés (`fr-FR`), alemán (`de-DE`), chino simplificado (`zh-CN`), hindi (`hi-IN`), árabe (`ar-SA`) y japonés (`ja-JP`). Cacti los muestra al activar idiomas (Configuration > Settings > General > Language Support) y elegir uno el usuario. También incluye español latinoamericano (`es-419`), pero Cacti 1.2 no ofrece esa configuración regional: solo surtirá efecto cuando Cacti la añada; mientras tanto, elija español (`es-ES`).

<a id="containers"></a>
## Contenedores

Ejecute el lector donde vea ambas cosas indicadas al principio.

- **Cacti en contenedor, SmokePing en el servidor.** Monte los datos en solo lectura en RRA y también la configuración en solo lectura:

  ```sh
  docker run -d --name cacti \
    -v /var/lib/smokeping:/var/www/html/cacti/rra/smokeping:ro \
    -v /etc/smokeping:/etc/smokeping:ro \
    my-cacti-image
  ```

  Sustituya el lado izquierdo del montaje de datos por el `DATADIR` del servidor. La imagen no necesita nada de SmokePing: ni Perl ni el paquete `smokeping`. PHP del contenedor debe ser 8.2 o posterior. Health comprueba `:ro`; sin él informa de error.
- **Docker Compose.** Lo mismo, como entradas `volumes:` con `read_only: true` y `type: bind`:

  ```yaml
  services:
    cacti:
      volumes:
        - type: bind
          source: /var/lib/smokeping
          target: /var/www/html/cacti/rra/smokeping
          read_only: true
  ```

- **Kubernetes.** Volumen con `readOnly: true` en `volumeMounts`, respaldado por hostPath o volumen NFS/CSI donde escribe el pod SmokePing.
- **SmokePing en contenedor.** Ponga su `datadir` en un volumen compartido con el servidor Cacti y móntelo en solo lectura como antes. El complemento nunca necesita entrar al contenedor SmokePing.

<a id="cactis-rrd-cleaner-will-list-smokeping-files"></a>
## El limpiador RRD Cacti enumera archivos SmokePing

El limpiador (**Utilities > RRD Cleaner**) recorre todo RRA, por lo que enumera como no usados los RRD SmokePing sin fuente Cacti, incluidos `target~slave.rrd`, y ofrece borrarlos. No los seleccione. Borrar o archivar mediante el montaje de solo lectura falla, Cacti registra una advertencia y elimina la entrada de la cola: los archivos sobreviven, pero las advertencias molestan y un montaje escribible perdería datos. El complemento elimina de la cola las entradas reconocibles antes de purgar (al comienzo de cada sondeo), reduciendo advertencias. El limpiador no tiene exclusiones; revise la lista antes de seleccionar.

<a id="upgrading-and-removing"></a>
## Actualizar y quitar

Sustituya el directorio por la nueva publicación y abra Plugins o espere al sondeo; migra tablas y plantillas y registra la versión solo al completar todos los pasos, por lo que la siguiente ejecución termina una actualización interrumpida. Deshabilitar detiene los trabajos y deja los archivos SmokePing intactos. **Antes de desinstalar, exporte la configuración usted mismo y compruebe que la copia de seguridad sea legible y esté completa.** La desinstalación intenta exportar la configuración del complemento a YAML en Backup Directory o `cache/smokeping/` de Cacti (nombre aleatorio, modo `0600`, ruta mostrada si tiene éxito). Si la exportación falla, se registra y muestra una advertencia, pero la desinstalación continúa. Elimina las tablas salvo que esté activo **Keep Plugin Tables on Uninstall**, y nunca toca los archivos ni la configuración de SmokePing.
