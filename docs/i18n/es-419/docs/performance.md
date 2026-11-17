<!-- Source: docs/performance.md; SHA256: fad77fc3956870dd042455a857a89703127ff8e2ed26a7f72b53c6c6619fabff -->

<a id="performance"></a>
# Rendimiento

La capacidad se expresa en series: un objetivo en una ubicación de medición (maestro o esclavo). Esta página indica el coste del complemento con 100, 1.000 y 10.000 series, el entorno medido y los datos aún pendientes. Los IDs de requisitos proceden de [#42](https://github.com/somethingwithproof/plugin_smokeping/issues/42).

<a id="what-the-plugin-does-per-request-and-per-cycle"></a>
## Trabajo por petición y por ciclo

- **Ninguna petición de página ejecuta una sonda, lee un RRD ni espera a un recolector remoto (REQ-PERF-003).** La vista general lee las filas de caché del lector en la base de datos. `tests/bin/perf-page.php` la dibuja con la ruta de montaje apuntando a un directorio inexistente y el resultado es igual. Los gráficos son gráficos Cacti y leen sus archivos como cualquier gráfico Cacti.
- **Un proceso lector por sondeador lee cada serie una vez por ciclo**, mediante una única tubería `rrdtool`, con un presupuesto temporal (120 s predeterminados). Si lo alcanza, se detiene y la siguiente ejecución continúa con las series que más esperaron. Leer un archivo es una llamada `lastupdate` que lee la cabecera; no depende del tamaño del RRD.
- **Cada fuente resumen lee una fila de caché**, sin tocar nunca un RRD.
- **La pantalla de confirmación de una adición masiva muestra la carga antes de crear nada (REQ-PERF-004)**: para los objetivos coincidentes, el número de series, paquetes de sonda por segundo y actualizaciones RRD por segundo que soportará SmokePing, así como filas de fuentes de datos Cacti y elementos de gráficos. El cálculo está en `lib/LoadEstimate.php`.

<a id="arithmetic"></a>
## Cálculos

Con 10.000 series, 20 pings cada 300 segundos: unos 667 paquetes por segundo de media, unas 33 actualizaciones RRD SmokePing por segundo y unas 33 lecturas `lastupdate` por segundo si el lector comprueba cada serie en cada paso. Los objetos Cacti son 260.000 filas `data_template_rrd` (23 para la fuente de humo y 3 para el resumen, por serie) y 550.000 filas de elementos de gráficos (50 para humo y 5 para resumen, por serie), con 10.000 fuentes resumen sondeadas por el servidor de scripts. La prueba de publicación siguiente usó plantillas anteriores a [#81](https://github.com/somethingwithproof/plugin_smokeping/issues/81) y contó exactamente sus filas: 47 por gráfico de humo, 520.000 en total. #81 añadió desviación estándar, am/s y líneas de sonda. La confirmación calcula los mismos números y `tests/Unit/LoadEstimateTest.php` los contrasta con las plantillas distribuidas.

<a id="local-smoke-measurements"></a>
## Mediciones locales preliminares

Medido en un portátil, en contenedores Docker, con scripts propios (`sh tests/run-perf.sh <work-dir>`): **medición local preliminar de este código, no cifra de publicación.** Equipo: Apple silicon, aarch64, 14 CPU y 15,7 GiB disponibles para Docker, MariaDB 10.11 y PHP 8.2 en contenedores del mismo servidor, RRD en un montaje enlazado Docker. Series: 20 pings, paso de 300 s, RRD de prueba de 193 KB cada uno (los originales SmokePing ocupan varios megabytes; `lastupdate` lee la cabecera, por lo que el tamaño no cambia la lectura).

| Series | Lector, primera pasada completa | Lector, archivos sin cambios | Series cubiertas en un ciclo predeterminado (120 s) | Vista general (CLI, solo base de datos) | Memoria máxima de página | HTML de página |
|---|---|---|---|---|---|---|
| 100 | 0.2 s | menos de 0.1 s | 100 de 100 | 0.002 s | 8 MB | 0.02 MB |
| 1,000 | 1.2 s | 1.1 s | 1,000 de 1,000 | 0.012 s | 12 MB | 0.17 MB |
| 10,000 | 12.3 s | 11.6 s | 10,000 de 10,000 | 0.119 s | 58 MB | 1.68 MB |

La tabla muestra un coste lector de unos 1,2 ms por serie en este entorno, con crecimiento lineal: 10.000 series consumen alrededor de una décima parte del presupuesto predeterminado en disco local. La vista general enumera todas las series asociadas en una página (unas 10.000 filas y 1,7 MB de HTML con 10.000), con poco tiempo de base de datos y PHP. Las cifras excluyen navegador, servidor web, marco de página Cacti, NFS y un SmokePing escribiendo simultáneamente.

No medido aquí: páginas de gestión Cacti con objetos aprovisionados (véanse las mediciones de publicación), montaje NFS de solo lectura y demonio SmokePing real escribiendo 33 archivos por segundo mientras lee el lector.

<a id="release-measurements"></a>
## Mediciones de publicación

Medido el 2026-11-15 en un droplet DigitalOcean: `c-4` en sfo3, 4 vCPU dedicadas (Intel Xeon Platinum 8280), 8 GB, Ubuntu 24.04, con Cacti 1.2.31, PHP 8.5.12, RRDtool 1.7.2 y MariaDB 10.11.19 en contenedores del mismo servidor. Las series tienen 20 pings y paso de 300 s, cada objetivo en su propio dispositivo, con RRD de prueba en un montaje enlazado de solo lectura.

<a id="reader-and-overview-page"></a>
### Lector y vista general

`sh tests/run-perf.sh <work-dir>`:

| Series | Lector, primera pasada completa | Lector, archivos sin cambios | Series cubiertas en un ciclo predeterminado (120 s) | Vista general (CLI, solo base de datos) | Memoria máxima de página | HTML de página |
|---|---|---|---|---|---|---|
| 100 | 0.2 s | menos de 0.1 s | 100 de 100 | 0.004 s | 8 MB | 0.02 MB |
| 1,000 | 1.9 s | 2 s | 1,000 de 1,000 | 0.028 s | 12 MB | 0.17 MB |
| 10,000 | 20.6 s | 19.9 s | 10,000 de 10,000 | 0.244 s | 58 MB | 1.68 MB |

El lector cuesta unos 2 ms por serie en este servidor: 10.000 series consumen alrededor de una sexta parte del presupuesto predeterminado.

<a id="cactis-pages-and-the-poller-with-every-object"></a>
### Páginas Cacti y sondeador con todos los objetos

`sh tests/run-perf-objects.sh <work-dir>` crea objetivos y dispositivos, mide las páginas, aprovisiona todos los objetos resumen y de humo mediante el complemento, vuelve a medir las páginas y ejecuta dos ciclos de sondeo, cada uno después de terminar el lector del anterior. `PERF_OBJECTS=stock` asigna en su lugar dos gráficos de las plantillas Local Linux Machine de Cacti a cada dispositivo («Unix - Load Average» y «Linux - Memory Usage»): el mismo número de gráficos por serie, para comparar REQ-PERF-002. Los tiempos de página son p50/p95 sobre HTTP en milisegundos, 20 peticiones tras una de calentamiento, con sesión de administrador y servidor integrado de PHP. «Solo dispositivos» son los mismos dispositivos antes de tener gráficos.

| Series | Objetos | Lista de dispositivos | Lista de fuentes | Lista de gráficos | Vista general del complemento |
|---|---|---|---|---|---|
| 100 | solo dispositivos | 28/31 | 25/35 | 35/37 | 38/40 |
| 100 | gráficos estándar | 28/39 | 48/56 | 33/48 | 29/39 |
| 100 | objetos del complemento | 28/39 | 51/53 | 33/34 | 28/29 |
| 1,000 | solo dispositivos | 30/42 | 25/26 | 26/36 | 59/64 |
| 1,000 | gráficos estándar | 32/33 | 50/51 | 39/40 | 65/66 |
| 1,000 | objetos del complemento | 42/44 | 54/77 | 39/40 | 58/68 |
| 10,000 | solo dispositivos | 50/57 | 28/37 | 28/30 | 301/319 |
| 10,000 | gráficos estándar | 66/69 | 50/75 | 107/111 | 331/338 |
| 10,000 | objetos del complemento | 65/68 | 54/78 | 105/119 | 318/340 |

| Series | Aprovisionamiento de objetos del complemento | Primer ciclo de sondeo (crea RRD) | Ciclo de sondeo estable | Lector |
|---|---|---|---|---|
| 100 | 31 s | 2.0 s | 1.6 s | 0.2 s |
| 1,000 | 324 s | 6.5 s | 5.1 s | 1.6 s |
| 10,000 | 3,284 s | 57.1 s | 43.5 s | 21.5 s |

Con 10.000 series, los objetos del complemento son 260.000 filas `data_template_rrd`, 520.000 filas de elementos gráficos y 10.000 elementos del sondeador; los gráficos estándar tienen 50.000 y 240.000 filas. El ciclo de sondeo es un proceso cmd.php que consulta las 10.000 fuentes resumen; el lector corre en paralelo, iniciado por `poller_bottom`. Ambos quedan muy por debajo del intervalo de 300 s. El aprovisionamiento es una adición masiva de un administrador: unos 0,33 s por serie, casi una hora para 10.000. Una ejecución anterior del mismo script en el mismo droplet tardó 6.141 s para 10.000 (0,61 s por serie); reserve entre una y dos horas.

<a id="against-the-requirements"></a>
### Frente a los requisitos

- **REQ-PERF-001 (vista general en menos de dos segundos con 10.000): cumplido.** p95 de 0,34 s sobre HTTP.
- **REQ-PERF-002 (páginas de gestión dentro del valor base más 20 % con 10.000): cumplido** frente a los gráficos estándar. Con 10.000 series, el p95 del complemento es un 1 % menor en dispositivos, un 4 % mayor en fuentes y un 7 % mayor en gráficos. Con 1.000, el p95 de fuentes supera el estándar un 51 % (77 frente a 51 ms), pero las medianas difieren un 8 %, en ambas ejecuciones: son las peticiones más lentas, no la típica. Frente a solo dispositivos, sin gráficos, cualquier conjunto de 20.000 gráficos, estándar o del complemento, ralentiza todas las páginas.
- **REQ-PERF-003 (sin sondas, lecturas RRD acotadas, sin espera remota por petición): cumplido** (véase el primer apartado).
- **REQ-PERF-004 (carga visible antes de adición masiva): cumplido.** La estimación coincide con las filas contadas.

<a id="supported-series-count"></a>
## Número de series admitido

**10.000 series por instalación Cacti**, con 20 pings y paso de 300 s, en hardware como el ejecutor de publicaciones: cuatro vCPU dedicadas y 8 GB, con la base de datos en el mismo servidor. A ese nivel, el lector consume una sexta parte del presupuesto predeterminado y el ciclo de sondeo una séptima parte del intervalo; las páginas de gestión se mantienen dentro del 7 % del mismo número de gráficos estándar. No se ha medido por encima de 10.000.

<a id="reproducing"></a>
## Reproducir

```sh
sh tests/run-perf.sh <work-dir>               # 100, 1,000, and 10,000 series
sh tests/run-perf.sh <work-dir> 1000          # one size
sh tests/run-perf-objects.sh <work-dir>       # Cacti's pages and the poller with every object
```

Cada ejecución prepara objetivos con `tests/bin/perf-seed.php`, crea RRD con `tests/bin/make-fixture-rrds.php`, los lee con `poller_smokeping.php --force` y dibuja la página con `tests/bin/perf-page.php`.
