<!-- Source: docs/operations.md; SHA256: 12e8fea195c9c3ab59312905bbe340b79a8c47ac405e9233953dbf8b583d545b -->

<a id="operating-the-smokeping-plugin"></a>
# Operar el complemento SmokePing

Cómo interpretar lo que muestra el complemento, el significado y las evidencias de cada estado, qué comprobar ante problemas y cómo responde a fallos de sus entradas. La instalación está en [instalar](install.md), las alertas en [alertas](alerting.md) y las copias en [copia y restauración](backup-restore.md).

Consulte [Roles de acceso](../README.md#access-roles) para los permisos View, Operator y Admin y el comportamiento de clasificación personal.

<a id="pages"></a>
## Páginas

| Página | Ámbito | Contenido |
|---|---|---|
| **SmokePing** (pestaña superior) | View | Último estado de los objetivos asociados a dispositivos que puede ver, una fila por objetivo y ubicación |
| **SmokePing Inventory** | Admin | Todos los objetivos de la configuración SmokePing, asociados o no, y propuestas abiertas de reasociación |
| **SmokePing Health** | Admin | Las comprobaciones siguientes y un botón para reintentar importar plantillas |
| **SmokePing Configuration** | Admin | Exportación e importación de configuración del complemento ([formato](configuration-format.md)) |
| **Import SmokePing Configuration** (en Import/Export) | Admin | Importación de una configuración SmokePing existente a dispositivos, gráficos y un árbol de gráficos ([procedimiento](provisioning.md#a-whole-smokeping-configuration)) |

Los gráficos de humo y resumen son gráficos Cacti normales, sujetos a sus permisos de gráficos y dispositivos.

<a id="what-each-state-means"></a>
## Significado de los estados

Cada fila de SmokePing combina dimensiones independientes. Nunca se fusionan en un color único, porque un recolector detenido y la pérdida completa de paquetes requieren respuestas distintas.

| Dimensión | Estados | Evidencia |
|---|---|---|
| Frescura | `current`, `delayed`, `stale`, `never` | Antigüedad de la última actualización RRD (leída con `rrdtool lastupdate`) frente al `step` del objetivo. Current: dentro de un `step` más la tolerancia de frescura (60 s predeterminados). Delayed: mayor antigüedad, hasta dos veces `step`, el heartbeat de SmokePing. Stale: más allá del heartbeat, cuando los valores son desconocidos. Never: falta el archivo o nunca se actualizó desde su creación |
| Ejecución | `completed`, `probe_error`, `unknown` | Una ronda se completó si escribió una pérdida numérica. Los datos obsoletos o nunca observados no informan de la última ronda, por lo que son `unknown`. Nunca se establece `probe_error`: una sonda fallida escribe lo mismo que un objetivo sin respuesta ([investigación](../../../research/probe-failures.md)) |
| Condición | `within_policy`, `degraded`, `complete_loss`, `unknown` | De la última ronda completada. Pérdida completa es `loss` igual a `pings` sin mediana. Cualquier pérdida inferior es degradación. Un recolector detenido nunca aparece como pérdida: queda obsoleto y la condición es `unknown` |
| Administrativa | `enabled`, `disabled`, `archived` | Registro del objetivo en el complemento. No se lee un objetivo deshabilitado o archivado |
| Mantenimiento | en mantenimiento o no | Hook `is_device_in_maintenance` de Maint para el dispositivo del objetivo, mostrado en la columna Maintenance de la página de objetivos. Sin Maint, o deshabilitado, todos los dispositivos aparecen sin mantenimiento |
| Hora de medición | segundos desde la última actualización | Marca temporal del propio RRD. Actualizar la página nunca cuenta como medición |

La frescura de cada ubicación procede de su propio archivo (`target~slave.rrd` para esclavos), de modo que un esclavo retrasado queda obsoleto aunque el maestro esté al día.

La columna de pérdida muestra `lost/pings` de la última ronda y la mediana está en milisegundos; `U` significa desconocido, no cero.

<a id="health-checks"></a>
## Comprobaciones de salud

| Comprobación | Qué significa OK | Cuando no es OK |
|---|---|---|
| `php_binary` | El binario PHP indicado en Cacti existe y es ejecutable | El lector no puede arrancar: corrija «PHP Binary Path» en Cacti |
| `reader` | La última ejecución terminó dentro de tres intervalos de sondeo y de su presupuesto temporal | Advertencia: lector retrasado o presupuesto excedido. Con muchas series, aumente el presupuesto o revise el almacenamiento; consulte [rendimiento](performance.md) |
| `inventory` | El último inventario analizó la configuración SmokePing | Error: mensaje del analizador SmokePing con archivo y línea. Se conserva el inventario anterior |
| `mount`, `read-only`, `writable` | La ruta de montaje está dentro de RRA, se alcanza sin enlaces simbólicos, está montada en solo lectura y no es escribible | Error. Consulte la [guía de instalación](install.md#3-mount-the-data-directory-read-only-inside-cactis-rra-path) |
| `visibility` | Los archivos enumerados por el inventario son visibles por el montaje | Advertencia con el número de ausentes |
| `templates` | Las plantillas SmokePing están importadas | Error con la causa; corríjala y use **Retry Template Import** |
| `device_pollers` | Todos los dispositivos con fuentes resumen son sondeados por el sondeador del lector | Error que nombra los dispositivos: sus valores y alertas carecen de datos hasta usar ese sondeador |
| `loss_colors` | Colores seleccionados por Loss Colors: los de la versión SmokePing encontrada o los indicados. Tras cambiar, el sondeador principal migra gráficos en ciclos siguientes, 200 por ciclo; `upgrade-graphs` los migra de inmediato. La fila cuenta los gráficos aún con otros colores y avisa de migraciones fallidas | Desconocido si el lector no encontró versión y el ajuste es Automatic: se usan colores de 2.8 y posteriores. Advertencia si difiere de la versión encontrada. Consulte [colores de pérdida](provisioning.md#loss-colors) |
| `dual_alerting` | Ningún objetivo tiene a la vez un valor `alerts` SmokePing y un umbral Thold habilitado en su fuente resumen | Advertencia con hasta 10 objetivos: un problema que cumpla ambas condiciones notifica dos veces. No se comparan condiciones; por ejemplo, un umbral Thold `age` puede activarse sin alerta de pérdida SmokePing. Compárelas antes de quitar la alerta SmokePing o deshabilitar el umbral. OK si no está instalado Thold |

<a id="typical-situations"></a>
## Situaciones habituales

- **Todo está `stale`.** SmokePing dejó de escribir o el montaje está obsoleto. Compruebe `systemctl status smokeping` y compare `rrdtool lastupdate` a través del montaje con el mismo archivo en el servidor SmokePing. NFS de solo lectura puede retrasarse por su caché de atributos.
- **Un esclavo está `stale` y el maestro `current`.** El esclavo dejó de entregar datos. El maestro aplica los resultados tardíos al recibirlos y rellena el gráfico; Thold no los reevalúa ([alertas](alerting.md)).
- **Un objetivo dice `never`.** El RRD aún no existe (SmokePing lo crea en la primera ronda tras recargar) o el montaje no lo muestra. Revise `visibility`.
- **Un gráfico queda vacío tras actualizar Cacti a 1.2.32.** Cacti sustituye una ruta de fuente fuera de RRA por su ruta generada. Verifique el montaje donde indica el complemento y que no haya enlaces simbólicos.
- **Se renombra una sección SmokePing.** SmokePing inicia un archivo nuevo para una ruta nueva. El inventario propone reasociar y no cambia nada hasta la confirmación de un administrador ([aprovisionamiento](provisioning.md#re-linking-a-renamed-target)).

<a id="topologies"></a>
## Topologías

1. **SmokePing en el servidor del sondeador Cacti.** El usuario del sondeador lee el directorio de datos mediante el montaje enlazado. Es el caso de la guía de instalación.
2. **Directorio SmokePing compartido con el servidor Cacti.** Móntelo en solo lectura por NFS en la ruta RRA del servidor que ejecuta el sondeador SmokePing. Salud compara la última actualización con el reloj; un montaje retrasado aparece como `delayed` o `stale`. Los gráficos de humo se dibujan en el servidor que sirve la interfaz web, que también necesita el recurso compartido; si solo lo tiene el sondeador, los resúmenes y alertas funcionan, pero los gráficos de humo quedan vacíos.
3. **SmokePing en un recolector remoto Cacti** pertenece a una fase posterior y no se admite en esta versión.

Solo el sondeador indicado en **SmokePing Poller** lee los datos SmokePing. Los dispositivos con fuentes resumen deben usarlo.

<a id="when-inputs-fail"></a>
## Ante fallos de entradas

Estos comportamientos están cubiertos por la calificación de la versión ([calificación de fallos](../../../failure-qualification.md)); ninguno pierde datos.

| Fallo | Comportamiento documentado |
|---|---|
| Configuración SmokePing ilegible o rota | Falla el inventario con el mensaje del analizador en Health; se mantiene el anterior sin eliminar nada |
| Una sonda no puede funcionar mientras SmokePing continúa: binario con error de salida, dirección origen ausente del servidor, parámetro rechazado por fping o IPv6 desactivado para FPing6 | SmokePing escribe pérdida completa para todos los objetivos de esa sonda, igual que para objetivos sin respuesta. Las mediciones muestran `complete_loss`. Si todos fallan a la vez, busque el error de la sonda en el registro SmokePing |
| Fallo de sonda detectado por SmokePing: binario ausente, parámetro fuera de rango, sin socket raw para fping | SmokePing termina al arrancar o en la primera ronda. Todos los objetivos pasan a `delayed` y después `stale`, como al detenerse SmokePing |
| No se resuelven nombres de objetivos (fallo DNS) | Las sondas fallan y escriben pérdida; el complemento lee lo escrito. Las mediciones muestran `degraded` o `complete_loss`, no obsolescencia. El complemento no hace consultas DNS |
| SmokePing o su recolector se detiene | Frescura pasa a `delayed` y luego `stale`; `median` y `loss_pct` pasan a desconocidos, `age` sigue creciendo. Nunca pérdida cero |
| Desfase de relojes entre Cacti y SmokePing | Frescura compara la hora propia del RRD con el reloj Cacti; el desfase altera todas las edades. SmokePing adelantado marca rondas futuras que aparecen `current`, y un recolector detenido sigue `current` hasta que transcurra el desfase. SmokePing atrasado hace parecer más antiguas todas las series y `stale` cuando supera dos veces `step`. Mantenga ambos servidores con NTP |
| RRD ilegible o corrupto | El lector registra una advertencia para ese archivo y sigue con los demás. Una serie nunca leída es desconocida (`U`). Una leída antes conserva la última lectura en caché, que envejece por `delayed` y `stale` hasta desconocida; un archivo dañado nunca se convierte en pérdida cero |
| Almacenamiento lleno en Cacti | El lector solo escribe su caché de base de datos. Si se rechaza una escritura, termina normalmente y todas las filas quedan completas como estaban; el siguiente ciclo tras recuperar espacio guarda las nuevas rondas. Los RRD Cacti de resumen fallan como cualquier otro origen |
| Lector terminado a mitad de ejecución | Cada fila se escribe completa, de modo que quedan lecturas completas. Cacti detecta el proceso muerto y la siguiente ejecución empieza inmediatamente y completa todas las series |
| Segundo arranque del lector | La tabla de procesos Cacti permite un lector por sondeador. El duplicado sale inmediatamente con 0 y registra una nota (nivel Medium); no perturba al primero |
| Inicio y fin de mantenimiento | Maint suprime notificaciones del dispositivo; Thold sigue evaluando y la fuente resumen se sigue sondeando |
| Actualización interrumpida del complemento | La versión registrada solo cambia al completar todos los pasos; la siguiente página o ciclo termina la actualización |

<a id="import-and-export"></a>
## Importar y exportar

Consulte el [formato de configuración](configuration-format.md). Forma breve:

```sh
php plugins/smokeping/cli/smokeping.php export --file=/var/backups/smokeping-config.yaml
php plugins/smokeping/cli/smokeping.php import --file=/var/backups/smokeping-config.yaml
```

<a id="logs"></a>
## Registros

El complemento escribe en el registro Cacti con la etiqueta `SMOKEPING`. `grep SMOKEPING log/cacti.log` muestra estadísticas del lector (`SMOKEPING STATS`), fallos de inventario e importación de plantillas y toda importación de configuración aplicada.
