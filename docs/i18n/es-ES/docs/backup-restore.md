<!-- Source: docs/backup-restore.md; SHA256: c0c5f5817de19e00bdaff571d69546d2c905683b183bfcffb3c7f776db9fa730 -->

<a id="backup-and-restore"></a>
# Copia de seguridad y restauración

Una pareja Cacti y SmokePing restaurada sigue funcionando cuando se restauran juntas tres cosas: la base de datos Cacti, la configuración SmokePing y su directorio de datos. Esta página explica qué contiene cada una, cómo respaldarlas, qué hacer después de restaurar y cómo recuperar la configuración propia del complemento desde su exportación YAML si se pierde la base de datos.

<a id="what-to-back-up"></a>
## Qué respaldar

| Elemento | Contenido | Por qué lo necesita el complemento |
|---|---|---|
| Base de datos Cacti | Tablas del complemento (objetivos, asociaciones, objetos creados, caché del lector, estado), dispositivos Cacti, fuentes de datos, gráficos y ajustes del complemento | Aquí residen las asociaciones, rutas de fuentes de datos y definiciones de gráficos |
| Configuración SmokePing: `/etc/smokeping/config` **y todos los archivos incluidos** (líneas `@include`, `config.d/*` en Debian y Ubuntu, archivo `secrets`, `smokemail`, `basepage.html`) | Árbol de objetivos | El inventario lo analiza. La identidad del objetivo es su ruta en este árbol y su archivo RRD es `datadir/<path>.rrd` |
| Directorio de datos SmokePing (`datadir`) | Archivos RRD | Todos los gráficos y valores resumen los leen; son el historial. No se pueden reconstruir |

Los ajustes del complemento están en la base de datos. La exportación descrita [más abajo](#restore-from-the-yaml-export) es una segunda copia, en texto, de la configuración propia del complemento; no sustituye a ninguno de los tres elementos.

<a id="taking-the-backups"></a>
## Realizar las copias

Haga el volcado de la base de datos y las copias de archivos aproximadamente al mismo tiempo, para que los objetivos de la base de datos y los archivos en disco describan la misma configuración.

```sh
# The Cacti database (use your own credentials file or option file, not a password on the command line)
mariadb-dump --single-transaction --defaults-extra-file=/root/.cacti-backup.cnf cacti > cacti.sql

# SmokePing's configuration, with its included files
tar -czf smokeping-config.tgz -C /etc/smokeping .

# SmokePing's data directory
tar -czf smokeping-datadir.tgz -C /var/lib/smokeping .

# The plugin's own configuration, as YAML (optional second copy)
php /var/www/html/cacti/plugins/smokeping/cli/smokeping.php export --file=/var/backups/smokeping-config.yaml
```

Use el `datadir` de su distribución (`/var/lib/smokeping/rrd` en EL y Fedora). SmokePing escribe los RRD continuamente. Una copia mientras funciona puede capturar un archivo a mitad de actualización; para que sea coherente, tome una instantánea del sistema de archivos o volumen, o detenga SmokePing durante la copia (`systemctl stop smokeping`; el intervalo es una ausencia de mediciones, no una pérdida del historial). El directorio de datos puede contener otros directorios (`__cgi` en Debian, `images` en Alpine); es seguro incluirlos u omitirlos.

El complemento nunca escribe en archivos SmokePing, por lo que restaurar el directorio de datos solo puede discrepar con lo que conserva el complemento a través de las asociaciones descritas abajo.

<a id="restoring"></a>
## Restaurar

1. Instale Cacti, PHP y SmokePing como indica la [guía de instalación](install.md), y la misma versión del complemento o una más reciente desde el archivo de publicación.
2. Restaure la base de datos Cacti.
3. Restaure la configuración y el directorio de datos SmokePing en las rutas de la copia e inicie SmokePing.
4. Monte el directorio de datos en modo de solo lectura en la ruta de montaje del complemento ([guía de instalación, paso 3](install.md#3-mount-the-data-directory-read-only-inside-cactis-rra-path)).
5. Deje actuar al sondeador o ejecute una vez `php plugins/smokeping/poller_smokeping.php --force --inventory`.

No hace falta nada más. Si los archivos del complemento son más recientes que la base de datos restaurada, migra sus tablas en la siguiente carga de página o ciclo del sondeador, y registra su versión solo cuando todos los pasos terminan correctamente.

Qué restaura esto y por qué funciona:

- **Las asociaciones resuelven los mismos RRD.** La ruta de una fuente es `<path_rra>/smokeping/<target path>.rrd`, relativa al montaje, y no cambia al cambiar el servidor o el directorio Cacti. La identidad de un objetivo es su ruta en el árbol SmokePing, no un ID de base de datos; la configuración restaurada conserva ese árbol.
- **El lector continúa.** Su caché conserva la última ronda vista por archivo; la siguiente ejecución lee el `lastupdate` de cada archivo y sustituye la fila. La primera ejecución recoge una ronda escrita por SmokePing mientras Cacti estuvo caído, y la frescura de cada serie se calcula desde la hora del archivo, no desde la última lectura.
- **Los gráficos se dibujan.** Son gráficos Cacti normales sobre las fuentes y archivos restaurados.

Compruebe **SmokePing Health**: montaje, lector e inventario están OK tras un ciclo del sondeador, y la página SmokePing enumera los objetivos asociados con frescura actual.

<a id="if-the-database-comes-back-older-than-smokepings-configuration"></a>
### Si la base de datos restaurada es más antigua que la configuración SmokePing

Un objetivo añadido a SmokePing después de la copia no está en la base de datos restaurada. El siguiente inventario lo encuentra y lo muestra sin asociar. Un objetivo eliminado desde entonces se marca como desaparecido y un administrador lo reasocia o archiva como siempre. No se pierde nada: los archivos siguen en el directorio de datos y la asociación se hace con un clic en **SmokePing Inventory**.

<a id="if-the-data-directory-is-restored-without-its-newest-rounds"></a>
### Si el directorio de datos se restaura sin sus rondas más recientes

SmokePing continúa escribiendo en los archivos restaurados desde su última actualización. El hueco en los gráficos corresponde al intervalo entre la copia y la restauración.

<a id="restore-from-the-yaml-export"></a>
## Restaurar desde la exportación YAML

Si se pierde la base de datos Cacti y solo queda la exportación, es posible recuperar los objetivos y asociaciones del complemento sin ella y volver a crear fuentes y gráficos:

1. Instale Cacti y el complemento en el nuevo servidor, restaure la configuración y directorio de datos SmokePing y monte este último como antes. Configure las rutas en Settings (no se importan; consulte el [formato](configuration-format.md)).
2. Vuelva a crear los dispositivos Cacti con el **mismo nombre de host y descripción**. Sus IDs pueden cambiar. La exportación identifica cada dispositivo asociado por esos dos valores.
3. Compruebe y aplique la exportación:

   ```sh
   php plugins/smokeping/cli/smokeping.php import --file=/var/backups/smokeping-config.yaml
   php plugins/smokeping/cli/smokeping.php import --file=/var/backups/smokeping-config.yaml --apply --confirm=<plan id>
   ```

   La comprobación enumera los cambios propuestos y rechaza una asociación cuyo dispositivo falte o sea ambiguo. No se escribe nada hasta el segundo comando, que necesita el ID de plan mostrado por el primero.
4. Para cada objetivo asociado, vuelva a crear fuentes y gráficos: `php plugins/smokeping/cli/smokeping.php provision <target path> --device=<device id>`. Apuntan a los mismos archivos de antes.

La exportación contiene ajustes, objetivos y asociaciones del complemento. No contiene fuentes, gráficos ni rutas RRD, por lo que hace falta el paso 4; la restauración desde una copia de base de datos no lo necesita.

<a id="uninstalling"></a>
## Desinstalar

La desinstalación escribe primero una exportación YAML (consulte la [guía de instalación](install.md#upgrading-and-removing)) y nunca toca los archivos SmokePing; por tanto, desinstalar y restaurar desde esa exportación funciona como el caso anterior.

<a id="verification"></a>
## Verificación

`sh tests/run-restore-e2e.sh <work-dir>` ejecuta ambas restauraciones en contenedores y bases de datos nuevos: prepara un dispositivo y fuentes en una instalación funcional, vuelca la base de datos y archiva la configuración y datos SmokePing, restaura los tres en contenedores nuevos mientras SmokePing escribe una nueva ronda, y comprueba que el complemento esté habilitado y su actualización no haga cambios, que cada asociación, dispositivo y ruta de fuente sea igual, que cada fuente resuelva bajo el montaje a un archivo cuya suma coincida con la copia, que un gráfico se dibuje y que, un ciclo después, la caché del lector contenga la nueva ronda. Después restaura solo la exportación YAML en una base nueva y comprueba que la asociación siga el nombre del dispositivo, que un inventario la mantenga y que el aprovisionamiento recree las fuentes sobre los mismos archivos.
