<!-- Source: docs/alerting.md; SHA256: 30514e9f4c05d71c7d9ae7406524d0fef0de3ca96907a7bc9c27842c1bc9ff72 -->

<a id="alerting-on-smokeping-data-with-thold-and-maint"></a>
# Alertas sobre datos SmokePing con Thold y Maint

El complemento no envía alertas. Proporciona a Cacti una fuente de datos «SmokePing Summary» sondeada para cada ubicación de un objetivo, y Thold y Maint trabajan sobre ella como sobre cualquier otra fuente ([ADR 0002](../../../decisions/0002-alert-through-cacti-data-sources.md)). Ninguno de los dos requiere cambios. Esta página es la referencia para operadores sobre los valores que comunica esa fuente y los límites del enfoque.

<a id="what-the-data-source-reports"></a>
## Qué comunica la fuente de datos

El sondeador de Cacti consulta cada fuente resumen mediante el servidor de scripts. El script solo lee una fila de la caché del lector: nunca abre un archivo RRD ni contacta con SmokePing durante el sondeo Cacti.

| Fuente de datos | Significado | Cuándo vale `U` (desconocido) |
|---|---|---|
| `median` | Mediana del tiempo de ida y vuelta de la última ronda completada, en milisegundos | La ronda supera el heartbeat de SmokePing (dos veces `step`), falta el RRD o nunca se actualizó, o la ronda no tenía mediana (pérdida completa) |
| `loss_pct` | `loss / pings x 100` de la última ronda completada | La ronda supera el heartbeat, falta el RRD o nunca se actualizó, o la ronda no escribió un valor de pérdida |
| `age` | Segundos desde la última actualización del RRD | El RRD no existe o el lector no tiene una fila de caché para él |

`median` y `loss_pct` nunca son `0` por falta de datos. Por tanto, un recolector detenido no indica pérdida cero. `age` sigue siendo numérico mientras exista el RRD, incluso después de que los otros dos pasen a `U`, de modo que la obsolescencia pueda generar una alerta. Un archivo creado pero nunca actualizado comunica el tiempo desde que el complemento vio por primera vez el objetivo.

Una ronda con antigüedad entre `step + freshness allowance` y dos veces `step` está «retrasada»: sus valores se siguen comunicando. La pérdida completa es `loss_pct` 100 con `median` `U`.

La fuente resumen debe sondearse desde el sondeador Cacti que ejecuta el lector (ajuste «SmokePing Poller»), porque la caché reside en la base de datos de ese sondeador. El complemento rechaza la creación de una fuente resumen en un dispositivo asignado a otro sondeador e indica cuál usar.

<a id="thresholds"></a>
## Umbrales

Cree umbrales Thold para `loss_pct`, `median` y `age`, desde plantillas Thold o en cada fuente de datos. Valores habituales:

| Alertar por | Umbral | Notas |
|---|---|---|
| Pérdida de paquetes | `loss_pct` alto en 5 a 10 | La pérdida completa es 100 |
| Latencia | `median` alto en su límite en ms | `U` no genera alerta de latencia |
| Recolector detenido o lento | `age` alto en 2 a 3 veces `step` | Único valor que informa de un recolector detenido |

Un umbral de `median` o `loss_pct` no se activa cuando el valor es `U`. Use `age` para «SmokePing dejó de medir».

<a id="limits"></a>
## Límites

Provienen del muestreo de Cacti y SmokePing, no del complemento, y este no puede eliminarlos.

**Las duraciones Thold deben ser al menos un `step`.** Cacti sondea con su propio intervalo y SmokePing mide con su propio `step`. Cuando el intervalo Cacti es menor que `step`, se comunica la misma ronda varias veces. Cuando es mayor, se omiten rondas. Así, una muestra fuera de umbral no equivale a una ronda fuera de umbral; establezca la duración de activación Thold en al menos un `step` (con paso predeterminado de 300 segundos e intervalo de sondeo de 300 segundos, una activación de 2 sondeos). Una duración menor que `step` puede alertar por una sola ronda contada dos veces.

**Los datos tardíos de esclavos nunca reactivan ni resuelven una alerta.** Un esclavo SmokePing guarda en caché los resultados que no pudo entregar y los reenvía; el maestro los aplica en orden temporal, por lo que pueden llegar tarde datos de una ventana pasada. Thold evalúa los valores cuando se sondean. Una ronda tardía actualiza el RRD y el gráfico, pero no cambia un valor ya evaluado por Thold: no genera una alerta perdida ni resuelve una abierta. Mientras un esclavo va retrasado, crece el `age` de esa ubicación, haciendo visible la demora.

**Las alertas solo ven el resumen.** Mediana, pérdida y antigüedad describen la última ronda. El gráfico de humo sirve para investigar la distribución.

**Las alertas propias de SmokePing son independientes.** La sección `alerts` de su configuración sigue funcionando. Un objetivo con alertas tanto en SmokePing como en Thold puede generar dos notificaciones por un problema si cumple ambas condiciones; el complemento no desactiva ninguna. La página de estado enumera estos objetivos bajo `dual_alerting`, a partir del valor `alerts` que lee el inventario, heredado igual que en SmokePing.

**El RRD Cacti es una copia.** Los valores resumen se guardan en el RRD de Cacti además del de SmokePing, por lo que una diferencia de retención o consolidación puede reflejarse en los gráficos.

<a id="maintenance"></a>
## Mantenimiento

Una programación Maint de tipo «Cacti device» en el dispositivo del objetivo suprime las notificaciones Thold para ese dispositivo. Thold sigue evaluando y el complemento sigue llenando la fuente resumen, sin huecos en gráficos ni `age`. Si el umbral sigue superado al terminar la programación, las notificaciones se reanudan después de alcanzar de nuevo el número de activaciones: Thold no cuenta las infracciones durante el mantenimiento del dispositivo.

Maint solo conoce dispositivos, no objetivos individuales. Un dispositivo con varios objetivos queda silenciado para todos. Las programaciones por objetivo requerirían un nuevo tipo de programación Maint y no forman parte de este complemento.

<a id="verification"></a>
## Verificación

`tests/run-alert-e2e.sh` ejecuta todo el recorrido en un contenedor con Cacti 1.2.31 y copias de `plugin_thold` y `plugin_maint` de un espacio de trabajo de complementos: un recolector de prueba añade rondas a un RRD con estructura SmokePing, el lector las guarda en caché, `poller.php` sondea la fuente resumen mediante el servidor de scripts, y Thold y Maint actúan sobre ella. Comprueba que un umbral `loss_pct` se active después del número configurado y se recupere, que una programación Maint por dispositivo suprima la notificación mientras continúa el sondeo, que la alerta se active al terminar el mantenimiento, y que un umbral `age` se active cuando el recolector de prueba se detiene y se recupere cuando vuelve a funcionar.
