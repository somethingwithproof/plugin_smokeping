<!-- Source: docs/provisioning.md; SHA256: 3a7e1fea196c12380b832900900588d2e2f745bcd71a27980c49bdb268d119c6 -->

<a id="adding-smokeping-targets-to-cacti"></a>
# Añadir objetivos SmokePing a Cacti

Cada objetivo descubierto por el inventario es visible para administradores en **SmokePing Inventory** e invisible para los demás hasta asociarlo a un dispositivo Cacti. No se crea nada sin confirmación de un administrador. El complemento nunca escribe archivos SmokePing.

<a id="one-target"></a>
## Un objetivo

En SmokePing Inventory, un objetivo presente sin asociar tiene el enlace **Add to Cacti**. La confirmación ofrece dos opciones:

- **Crear un dispositivo nuevo** desde la plantilla «SmokePing Target». Usa el host del objetivo, desactiva la comprobación de disponibilidad (SmokePing ya lo mide) y se asigna al sondeador del lector.
- **Asociarlo a un dispositivo existente.** La lista muestra dispositivos que puede modificar.

En ambos casos recibe una fuente y gráfico resumen para alertas (véase [alertas](alerting.md)) y, si los datos SmokePing pueden representarse, un gráfico de humo por ubicación. No recibe gráfico de humo, y la página explica por qué, si `pings` no es 10, 20 o 30, si Cacti almacena RRD en RRDproxy o si el archivo no es visible mediante el montaje de solo lectura. Conserva sus datos resumen.

La fuente resumen la sondea el sondeador que ejecuta el lector. Si un dispositivo existente usa otro sondeador, el complemento no crea la fuente e indica cuál usar. Salud enumera dispositivos cambiados a otro sondeador posteriormente.

<a id="many-devices"></a>
## Muchos dispositivos

En la lista de dispositivos, seleccione varios y elija **Add SmokePing Targets**. La confirmación enumera, por dispositivo, los objetivos sin asociar cuyo `host` SmokePing coincide con su nombre de host (sin distinguir mayúsculas), y el total de coincidencias. Continuar los añade. Los dispositivos sin coincidencia se indican y quedan intactos; no se crea ningún dispositivo. Cien dispositivos en una petición tardaron unos 24 segundos en un contenedor local; una selección muy larga deja de iniciar nuevos dispositivos poco antes del límite de petición. Repetir termina el trabajo porque se omiten los ya añadidos.

<a id="a-whole-smokeping-configuration"></a>
## Una configuración SmokePing completa

**Console > Import/Export > Import SmokePing Configuration** incorpora una instalación SmokePing existente a Cacti en un paso. Requiere el ámbito SmokePing Admin y permisos que cubran todos los dispositivos; reflejarla en un árbol requiere también Trees.

Elija la entrada:

- **El archivo configurado** (predeterminado): el ajuste SmokePing Configuration File, leído en el servidor con todos sus archivos incluidos. Cada inclusión debe permanecer en ese directorio o debajo.
- **Un archivo cargado**, de hasta 4 MiB. Se lee de forma independiente, por lo que `@include` se rechaza con su número de línea. Los objetivos se representan desde los RRD visibles al sondeador del lector; el próximo inventario marca ausentes los que no existan en el SmokePing de ese sondeador.

Después, las opciones:

| Opción | Predeterminado | Significado |
|---|---|---|
| Dispositivos | Uno por host SmokePing | Los objetivos que sondean el mismo host comparten dispositivo. Uno por objetivo da a cada uno un dispositivo con su título como nombre. |
| Plantilla de dispositivo | SmokePing Target | Plantilla de los dispositivos creados. |
| Sondeador | El del lector | Debe ejecutar el lector; se rechaza cualquier otro porque sus fuentes resumen no obtendrían datos. |
| Sitio | Ninguno | Sitio de los dispositivos nuevos. |
| Árbol de gráficos | No reflejar | Árbol existente o nuevo por nombre. Cada sección SmokePing forma una rama, cada dispositivo una entrada de host bajo la rama de sus objetivos. |

**Preview** lee sin escribir nada. Muestra secciones con casillas (selecciónelas y pulse **Update Preview** para importar solo esos objetivos), dispositivos que crear o reutilizar, gráficos que crear, objetivos solo con resumen y su motivo (`pings` distinto de 10, 20 o 30, o RRDproxy), ramas y entradas del árbol y carga estimada, igual que la acción masiva. Se reutiliza un dispositivo si, en el sondeador elegido, tiene el host del objetivo (y, con uno por objetivo, su título como descripción); si coinciden dos, se omiten y enumeran esos objetivos para asociarlos en SmokePing Inventory. **Import** aplica exactamente el plan mostrado con las funciones Cacti de dispositivos, gráficos y árboles. Si cambió la configuración o Cacti desde la vista previa, no escribe nada y debe repetirla.

Importar otra vez no crea nada nuevo: los objetivos asociados conservan dispositivos, se reutilizan gráficos y entradas del árbol, y cada objetivo se indica como ya existente en Cacti. Nunca se escribe en archivos SmokePing ni en el montaje.

La misma importación por consola, con iguales opciones:

```sh
php plugins/smokeping/cli/smokeping.php import-smokeping --dry-run --tree-name=SmokePing
php plugins/smokeping/cli/smokeping.php import-smokeping --apply --confirm=PLAN --tree-name=SmokePing
```

`--file=PATH` lee otro archivo de forma independiente, como una carga. `--sections=Europe,Lab/Core`, `--device-per=target`, `--device-template=ID`, `--poller=ID`, `--site=ID` y `--tree=ID` establecen las otras opciones. La simulación muestra el plan y su ID; `--apply` necesita ese ID y aún debe coincidir.

<a id="graph-scale"></a>
## Escala de gráficos

SmokePing dimensiona cada gráfico desde sus datos en cada renderizado. Cacti tiene un único límite superior para todos los periodos, por lo que el complemento aplica al último día la regla de los gráficos detallados de SmokePing (`findmax` en `Smokeping.pm`): 1,2 veces la mayor mediana del último día en el archivo del gráfico. El Navigator Graph de SmokePing usa 1,5; el complemento no tiene navegador. Dimensiona al crear y después aproximadamente a diario. Si no hay mediana en el último día, conserva el límite; uno nuevo conserva el límite de plantilla de 0,05 s (50 ms).

El ajuste diario está activo de forma predeterminada y no tiene opción de configuración. Después de cada lectura, el lector dimensiona los gráficos de objetivos de su sondeador cuyo último ajuste fue hace un día o más, primero los que más esperan, hasta 25 por ciclo. Lee un RRD por gráfico y no ejecuta sondas. Omite el ciclo si la lectura agotó el presupuesto. Con un ciclo cada cinco minutos cubre 7.200 gráficos diarios; con más, cada gráfico se ajusta algo menos que diariamente.

El complemento registra el límite que puso en cada gráfico. Un límite diferente fue configurado manualmente en la página del gráfico y nunca lo cambia. Para devolver el control, restablezca el último límite del complemento, que `size-graphs` muestra para cada gráfico con límite manual.

Para dimensionar todos ahora:

```sh
php plugins/smokeping/cli/smokeping.php size-graphs
```

Muestra el nuevo límite o si conservó el existente o el manual. Respeta las mismas reglas de límites manuales que el ajuste diario. Necesita la ruta rrdtool de Cacti (Configuration > Settings > Paths); sin ella conserva los límites.

Al actualizar desde 1.0.0-rc.1 no hay registro de quién fijó los límites existentes: el complemento adopta el actual como propio y ajusta el gráfico en su próximo turno diario. Antes, `size-graphs` también sustituía los límites manuales. Un límite solo cuenta como manual mientras difiere del registrado, y tras actualizar el registrado es el adoptado. Para conservar uno manual, vuelva a fijarlo después de que el ajuste diario lo sustituya. Si ese ajuste no encuentra mediana, conserva el adoptado, de modo que volver a poner el mismo valor no cambia nada y un ajuste posterior aún podrá sustituirlo.

<a id="graph-legend-and-template-upgrades"></a>
## Leyenda y actualización de plantillas

La leyenda sigue la de SmokePing: promedio, máximo, mínimo y último valor de la mediana, desviación estándar y am/s (promedio dividido por desviación estándar), después pérdidas y colores y la línea de sonda, como `probe: 20 ICMP Echo Pings (56 Bytes) every 300s`. La línea procede de sonda, tamaño de paquete, pings y paso leídos del objetivo en la configuración. El sondeador principal la escribe en cada gráfico y vuelve a hacerlo si cambia la sonda o una importación de plantillas la restablece. Un objetivo guardado antes de leer la sonda muestra su nombre.

Si una actualización trae plantillas nuevas, los gráficos anteriores conservan sus elementos, que siguen dibujándose, hasta que el complemento los migra. El sondeador principal migra 200 por ciclo, conservando título y límite superior. Para todos ahora:

```sh
php plugins/smokeping/cli/smokeping.php upgrade-graphs
```

La actualización establece los elementos según la plantilla, sustituyendo cambios manuales de elementos de gráficos del complemento. Después comprueba que coincidan exactamente y existan todos sus VDEF. Si falla, conserva elementos y versión anteriores, y el registro Cacti nombra el gráfico. El sondeador no reintenta. `upgrade-graphs` reintenta cada fallido en cada ejecución y sale con 1 si alguno sigue fallando.

<a id="loss-colors"></a>
## Colores de pérdida

SmokePing 2.7 colorea la pérdida con siete niveles fijos; desde 2.8 usa proporciones de pings y añade pérdida completa. El complemento distribuye plantillas para ambos sobre las mismas fuentes y ambas coinciden con los gráficos originales ([fidelidad](../../../research/graph-fidelity.md)). **Loss Colors** en los ajustes elige:

- **Automatic** (predeterminado) sigue la versión que el lector encuentra en `Smokeping.pm` junto a SmokePing (`/usr/share/perl5`, `/usr/share/smokeping`, `/opt/smokeping/lib` o `/usr/local/smokeping/lib`), registrada en cada inventario. Por debajo de 2.8 usa colores 2.7. Si no encuentra versión, por ejemplo en otro contenedor, usa 2.8 y posteriores.
- **SmokePing 2.8 and later** o **SmokePing 2.7** fija los colores independientemente de lo encontrado.

La fila `loss_colors` de Salud muestra los colores y el motivo. Al cambiar por ajuste o actualización de SmokePing, el sondeador principal migra los gráficos a otras plantillas, 200 por ciclo, conservando título, límite y datos. `upgrade-graphs` los migra todos de inmediato. Un gráfico fallido conserva colores y se reintenta en el próximo `upgrade-graphs`, como antes.

<a id="re-linking-a-renamed-target"></a>
## Reasociar un objetivo renombrado

SmokePing nombra el archivo según su lugar en el árbol, por lo que renombrar o mover un nodo inicia un archivo vacío. Si una importación ve desaparecer una ruta y aparecer otra con igual host y sonda, registra una propuesta sin cambios. **Review** en Inventory abre la confirmación. Reasociar mueve el gráfico de humo, la fuente resumen y la asociación de dispositivo al nuevo objetivo, y mantiene legible el historial antiguo en un gráfico archivado. **Dismiss Proposal** cierra sin cambios.

<a id="permissions"></a>
## Permisos

El complemento tiene tres ámbitos. **SmokePing View** permite abrir la página, que solo enumera objetivos asociados a dispositivos visibles al usuario, y seguir el enlace desde los gráficos del dispositivo. **SmokePing Operator** añade la página Operations y reconocimientos personales de observaciones actuales; no cambia la salud medida ni suprime alertas. **SmokePing Admin** incluye acceso Operator y abre inventario, salud y las acciones anteriores. Un administrador con permisos limitados ve objetivos sin asociar y objetivos de dispositivos que puede ver.

Gráficos y fuentes son objetos Cacti normales: sus permisos de gráficos y dispositivos deciden quién los ve. El método predeterminado Cacti es permisivo: solo se limita a ciertos dispositivos cuando las políticas de dispositivo, gráfico y plantilla de gráfico deniegan todas. Los cambios se aplican en la siguiente petición del usuario.

<a id="deleting-a-device"></a>
## Eliminar un dispositivo

Eliminar un dispositivo asociado borra su asociación y registros del complemento sobre sus fuentes, dejando el objetivo en inventario sin asociar. No toca archivos SmokePing: véase [ADR 0007](../../../decisions/0007-read-only-mount-under-rra-path.md).
