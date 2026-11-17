<!-- Source: docs/configuration-format.md; SHA256: ec68c633a93e30aa23abef57fd66184cc2e8857c66333a82ce211fc72938f7c1 -->

<a id="configuration-file-format"></a>
# Formato del archivo de configuración

La configuración propia del complemento se exporta e importa en un único formato con dos representaciones: YAML (predeterminada) y XML. Esta página define la versión 1 del esquema. Los motivos del diseño están en la [decisión 0008](../../../decisions/0008-yaml-and-xml-configuration-files.md).

El archivo contiene los ajustes del complemento, los objetivos SmokePing que ha leído (objetivos observados) y el dispositivo Cacti asociado a cada objetivo. No contiene fuentes de datos, gráficos, rutas RRD ni credenciales. Las plantillas de gráficos, datos y hosts de Cacti son algo distinto y siguen en XML Cacti.

<a id="commands"></a>
## Comandos

```sh
php plugins/smokeping/cli/smokeping.php export                         # YAML to standard output
php plugins/smokeping/cli/smokeping.php export --format=xml --file=/var/backups/smokeping-config.xml
php plugins/smokeping/cli/smokeping.php import --file=smokeping-config.yaml           # check only
php plugins/smokeping/cli/smokeping.php import --file=smokeping-config.yaml --apply --confirm=<plan>
```

`export --file` crea un archivo nuevo legible solo por su propietario y rechaza sobrescribir uno existente. `import` sin `--apply` lee y comprueba el archivo, muestra todos los cambios propuestos y un ID de plan, y no escribe nada. `--apply` necesita `--confirm` con ese ID; aplica en una sola transacción y rechaza si el archivo o la instalación cambiaron desde la comprobación. Estado de salida: 0 completado, 1 rechazado o fallido, 2 errores en el archivo o plan.

Las mismas acciones están en **Management > SmokePing Configuration** (ámbito SmokePing Admin): descargue una exportación, elija un archivo para comprobar, revise los cambios enumerados y aplique.

La importación solo añade y actualiza. Nunca elimina objetivos, mueve asociaciones a otro dispositivo (reasocie en Inventory), crea fuentes, gráficos o archivos RRD, ni lee o escribe en el directorio de datos SmokePing. La desinstalación escribe una exportación YAML en el ajuste **Backup Directory** o, si está vacío, en `cache/smokeping/` dentro del directorio Cacti. El archivo tiene un nombre aleatorio y permisos `0600`; el directorio predeterminado contiene un `.htaccess` que deniega todo y un `index.php` que no sirve nada. Si Backup Directory no existe o no es escribible, no se exporta (no se recurre al directorio predeterminado), y la desinstalación continúa indicando dónde se escribió el archivo o que no se escribió ninguno. Elija un directorio fuera de la raíz web.

<a id="schema-version-1"></a>
## Versión 1 del esquema

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

Identidad. Un objetivo se identifica por `poller_id` y `path`. Un dispositivo por `hostname` y `description`; la comprobación rechaza asociaciones cuando ningún dispositivo, o más de uno, tiene esa pareja. La importación recorta espacios al principio y al final del texto porque el complemento lee todos los valores almacenados de ese modo.

Claves reservadas. `managed_targets` y `probe_profiles` se reservan para una futura versión con modo gestionado. La versión 1 las rechaza con un error que nombra la clave, para que nunca se aplique parcialmente un archivo escrito para esa versión. Una versión posterior ampliará el esquema con ellas; solo aumenta `schema_version` si cambia el significado de una clave ya existente aquí.

Actualizaciones. Para un objetivo ya presente, una importación solo cambia `host`, `probe`, `menu`, `title`, `slaves`, `nomasterpoll`, `step`, `pings` y `admin_state`. La configuración SmokePing sigue siendo la fuente de los seis primeros campos: el siguiente inventario los restaura. Use la importación para restaurar `admin_state` y preparar una instalación nueva, no para editar objetivos.

Los valores de ajustes deben tener el tipo indicado. El texto no puede contener caracteres de control.

Ajustes solo de referencia. Un ajuste que nombra un archivo o directorio (todos los de `reference_settings` arriba) nunca se escribe mediante importación: un archivo no debe poder cambiar dónde lee o escribe el complemento. El esquema los marca por tipo y rechaza un archivo que los ponga en `settings`. En `reference_settings` se comprueba su forma (ruta absoluta o vacío donde se permita), se comparan con la instalación y, si difieren, el plan muestra `not applied, change on the Settings page`. Cámbielos en Settings. Los archivos exportados por 1.0.0-rc.1 también enumeran ahí `smokeping_perl_binary` y `smokeping_lib_dir`; estos ajustes ya no existen y la importación los omite.

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

XML se corresponde uno a uno con las claves YAML anteriores. Los booleanos son `true` o `false`. El lector rechaza cualquier declaración DOCTYPE o de entidad, no carga recursos externos y solo acepta estos elementos.

<a id="yaml-subset"></a>
## Subconjunto YAML

El lector acepta mapas y listas en bloque, escalares simples y entre comillas simples o dobles, comentarios y bloques literales `|` y `|-`, con un único `---` inicial opcional. Los escalares entre comillas dobles admiten escapes `\\ \" \/ \n \t \r \0 \b \f \e \xHH \uHHHH`; los de comillas simples duplican la comilla (`''`). Un escalar entrecomillado se cierra en su propia línea.

Rechaza, indicando la línea: anclas (`&x`), alias (`*x`), etiquetas (`!x`), colecciones en flujo (`[a, b]`, `{a: b}`), bloques plegados (`>`), múltiples documentos, directivas, claves de fusión (`<<`), claves complejas (`?`), claves duplicadas, tabulaciones de sangría, escalares simples multilínea y anidamiento superior a 8 niveles. Rechaza un escalar simple que otra versión YAML interpreta como algo distinto de texto: `yes`, `no`, `on`, `off`, `y`, `n`, números con cero inicial, decimales, hexadecimales, fechas y horas como `1:30`. Póngalos entre comillas (`'yes'`). `true`, `false`, `null`, `~` y los números enteros simples conservan su significado.

El exportador entrecomilla toda cadena que un lector pudiera interpretar mal, incluidas aquellas con dos puntos o `#`, espacio inicial o final, o un primer carácter que YAML trate como indicador.

<a id="limits"></a>
## Límites

En ambos formatos, los archivos se limitan a 8 MiB y 8 niveles de anidamiento, y cada línea a 16 KiB. El tamaño se comprueba antes de leer el archivo.
