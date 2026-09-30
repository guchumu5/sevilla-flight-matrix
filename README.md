# Matriz Operativa de Cintas · Sevilla

Aplicación web PHP/MySQL para vigilar las llegadas físicas a Sevilla, registrar el historial de sala/cinta y aplicar la matriz operativa acordada.

Cada subida a `main` ejecuta una comprobación automática con PHP y MySQL 8:
sintaxis, creación del esquema, dos capturas consecutivas y repetición
idempotente. El ensayo exige un único vuelo físico, dos observaciones y un solo
evento `belt_changed`.

## Requisitos

- PHP 8.1 o superior con extensiones `pdo_mysql`, `curl` y `json`.
- MySQL 8 o MariaDB 10.6 o superior.
- Apache o Nginx, o el servidor integrado de PHP para pruebas.

## Instalación rápida

1. Copia `.env.example` como `.env` y completa la conexión MySQL.
2. Crea una base de datos UTF-8 (`utf8mb4_unicode_ci`), selecciónala e importa `database/schema.sql`.
3. Opcionalmente importa `database/demo.sql`.
4. Genera la contraseña del administrador:

   ```bash
   php -r "echo password_hash('CAMBIA_ESTA_CLAVE', PASSWORD_DEFAULT), PHP_EOL;"
   ```

   Copia el resultado en `ADMIN_PASSWORD_HASH` dentro de `.env`.

5. Para probar localmente:

   ```bash
   php -S localhost:8080 -t public
   ```

6. Abre `http://localhost:8080`.

Para XAMPP, cPanel y el alta de las APIs, sigue `GUIA_INSTALACION.md`.

### Cargar la programación del 24 al 27 de septiembre de 2026

El fichero `database/seed_arrivals_2026-09-24_27.sql` contiene 408 llegadas
físicas agrupadas por vuelo, con sus códigos compartidos y las primeras
asignaciones preliminares de sala/cinta publicadas por Aena. La importación es
idempotente: puede repetirse sin duplicar vuelos, códigos ni la instantánea
inicial de Aena.

Desde consola:

```bash
mysql -u TU_USUARIO -p TU_BASE_DE_DATOS < database/seed_arrivals_2026-09-24_27.sql
```

También puede importarse desde phpMyAdmin seleccionando la base de datos,
abriendo **Importar** y cargando ese mismo fichero. Después, el selector de
fecha del panel permite abrir cualquiera de los cuatro días.

### Actualizaciones de MySQL desde la aplicación

El administrador incluye la sección **Actualizar MySQL**. Desde ella se pueden
consultar y ejecutar únicamente los paquetes versionados incluidos en
`database/updates/`. Cada ejecución queda registrada en `schema_migrations`
con su huella SHA-256, fecha, duración y número de sentencias ejecutadas.

El actualizador no permite pegar SQL ni subir scripts desde el navegador. Esta
limitación evita convertir el panel administrativo en una consola SQL remota.
Para publicar una modificación se añade un manifiesto PHP nuevo y su fichero
SQL al repositorio; después aparecerá como pendiente en la aplicación.

### Cerebro de conciliación y eventos

La versión 1.2 compara cada captura con el último estado del mismo proveedor.
No sobrescribe el pasado: añade observaciones y genera eventos inmutables para
altas, cambios de horario, ETA o estado, primera cinta, cambio o retirada de
cinta, equipaje, cancelación, ausencia, retirada y reaparición.

Los eventos guardan valor anterior y nuevo, fuente, hora, evidencia, confianza
y motivo. Cuando la fuente no publica la causa operativa, el sistema registra
expresamente **causa no publicada**; nunca inventa una explicación.

Aplica primero el paquete `20260923_002_event_brain` desde **Actualizar MySQL**.
Después puede probarse con el contrato JSON incluido:

```bash
php bin/sync-json.php --file=database/examples/sync_flights.example.json
php bin/sync-json.php --file=database/examples/sync_flights.changed.example.json
```

El resultado puede consultarse desde **Administración → Cerebro de eventos**.
Una ventana completa vacía se rechaza por defecto y un vuelo solo se considera
retirado tras tres ausencias completas consecutivas. Esto protege frente a
fallos temporales del proveedor.

Cada registro JSON representa una instantánea completa de ese vuelo en su
fuente. Si una cinta desaparece en la segunda captura, debe enviarse como
`"belt": null`; así el cerebro puede distinguir una retirada real.

## Automatización

### Cintas oficiales de Aena

La versión 1.4 incorpora un recolector de Infovuelos con navegador real. Lee el
tablero completo por ventanas horarias, agrupa los códigos compartidos en un
único vuelo físico y envía a MySQL estado, hora efectiva, sala y cinta. Cada
captura pasa por el conciliador: una asignación, cambio o retirada genera un
evento y conserva la observación anterior.

El navegador se ejecuta en GitHub Actions porque un cron PHP normal no ejecuta
la aplicación JavaScript de Infovuelos. Para activarlo:

1. Genera una cadena aleatoria de al menos 24 caracteres y guárdala en el `.env`
   del servidor como `AENA_INGEST_TOKEN=...`.
2. En GitHub abre **Settings → Secrets and variables → Actions → New repository
   secret**, crea `MATRIX_INGEST_TOKEN` y pega exactamente la misma cadena.
3. En la pestaña **Actions** abre **Aena en directo** y pulsa **Run workflow**
   para la primera prueba. Después se ejecutará cada 15 minutos. La acción
   **Aena programación semanal** carga hoy y los seis días siguientes una vez
   al día con el mismo secreto; tampoco necesita cron en Plesk.
4. Si la aplicación no está en `https://ojito.top/public`, crea además la
   variable de repositorio `MATRIX_INGEST_URL` con la URL completa del endpoint
   `public/api/aena-board.php`. Para la instalación actual no hace falta.

El endpoint exige token Bearer, limita el tamaño y el número de vuelos y no
acepta SQL ni comandos. La ventana completa vacía se rechaza, de modo que un
fallo temporal de Aena no puede retirar masivamente vuelos.

La corrección 1.11.4 tolera las cargas intermitentes de Infovuelos: espera hasta
60 segundos a que el formulario y el botón de búsqueda estén realmente listos
y, si Aena aun así falla, GitHub Actions repite una vez la captura completa a
los 20 segundos. Solo el segundo fallo consecutivo deja la ejecución en rojo,
por lo que no se oculta una caída real del servicio.

### Uso completamente web

La versión 1.3 añade **Administración → Procesos web**. Desde esa pantalla se
puede comprobar si las claves y tablas están disponibles y ejecutar AirLabs,
OpenSky y AviationWeather sin abrir una terminal. Las acciones están fijadas
en el servidor, requieren la sesión administrativa y token CSRF, limitan el
número de vuelos y bloquean ejecuciones simultáneas.

Cada intento queda además registrado en `fetch_runs`, incluso si devuelve cero
vuelos o termina con error. Así se distingue un cron que no alcanzó PHP de una
API ejecutada correctamente pero sin datos. `sync_runs` solo contiene lotes que
sí llegaron al conciliador de vuelos.

Antes del primer uso aplica desde **Actualizar MySQL** el paquete
`20260924_003_fetch_runs`. El cerebro muestra estos intentos por separado de
las sincronizaciones que sí produjeron una captura válida.

AirLabs se consulta mediante dos lotes de hasta 50 registros: `/schedules`
aporta horario, estado y equipaje secundario, mientras `/flights` aporta la
identidad y telemetría disponible de los vuelos que están realmente en ruta.
La aplicación los concilia localmente por ruta, fecha, vuelo físico y códigos
compartidos. Si el plan o la cuota no permiten `/flights`, el lote de horarios
continúa funcionando y deja el diagnóstico correspondiente. El `.env` admite
`AIRLABS_API_KEY_1`, `AIRLABS_API_KEY_2` y `AIRLABS_API_KEY_3`; también
mantiene `AIRLABS_API_KEY` por compatibilidad. Las claves se usan por turnos y,
si una responde con límite o credencial caducada, se prueba la siguiente sin
mostrar nunca su valor.

AirLabs ya entra por el mismo conciliador que el importador JSON: conserva cada
instantánea y genera los cambios correspondientes en el cerebro. Las
observaciones de Aena introducidas en el formulario administrativo también
generan eventos. OpenSky añade telemetría ADS-B y AviationWeather guarda el
METAR, que son evidencias complementarias y no sustituyen la autoridad de Aena.

Los scripts de `bin/` se conservan para automatizar desde el panel web de Plesk
o cPanel. No deben hacerse accesibles como direcciones web.

Como respaldo de Plesk, el workflow **Proveedores de respaldo** llama a un
endpoint protegido con el mismo secreto de ingestión. Antes de consultar,
comprueba la última ejecución local: evita duplicar una tarea de Plesk que sí
esté funcionando. OpenSky respeta su pausa de cuota y una respuesta 429 deja el
proceso en estado **EN PAUSA**, no como una avería ni como un job fallido.

`bin/sync-json.php` es exclusivamente de consola. Para importar el mismo
contrato JSON sin terminal, entra en **Administración → Procesos web → Importar
captura JSON**. El endpoint web exige sesión administrativa y CSRF, limita el
lote y lo envía al mismo conciliador.

Desde **Administración → Cerebro de eventos** puede activarse **Avisos web**.
El navegador consulta cada 30 segundos y notifica únicamente eventos materiales
nuevos (cinta, sala, ETA, cancelación, puerta, posición y equipaje) mientras la
página permanezca abierta o en segundo plano. No repite eventos ya vistos.

La actualización `20260929_005_push_watch` añade avisos Web Push reales. Tras
aplicarla desde **Actualizar MySQL**, el botón **Activar avisos** registra el
móvil, pero no añade vuelos automáticamente. En la ficha de cada vuelo debe
pulsarse expresamente **Vigilar este vuelo**; solo esos vuelos generan avisos.
Las alertas amarillas de Canarias dentro del tablero siguen siendo información
visual, pero no crean notificaciones del sistema si el vuelo no está vigilado.
La vigilancia móvil solo interrumpe en cinco hitos: **despega**, **asignación
oficial de cinta**, **cambio oficial de cinta**, **aterriza** y **equipaje en la
cinta**. El resto de cambios continúa guardado en el cerebro sin generar ruido.
Para despachar la cola aunque la web esté cerrada, añade en Plesk:

```cron
* * * * * /opt/plesk/php/8.3/bin/php /var/www/vhosts/ojito.top/httpdocs/bin/dispatch-push.php >> /var/www/vhosts/ojito.top/httpdocs/storage/logs/push.log 2>&1
```

Las claves VAPID se generan automáticamente en `storage/keys/vapid.json` y la
clave privada nunca se entrega al navegador ni debe subirse al repositorio.

### Copias automáticas

**Administración → Copias** crea, verifica y descarga copias comprimidas de
todas las tablas. Cada fichero lleva suma SHA-256; la retención automática
conserva 7 puntos diarios, 5 semanales y 12 mensuales. Programa en Plesk:

```cron
15 3 * * * /opt/plesk/php/8.3/bin/php /var/www/vhosts/ojito.top/httpdocs/bin/backup.php >> /var/www/vhosts/ojito.top/httpdocs/storage/logs/backup.log 2>&1
```

En Plesk, crea tareas de tipo **Ejecutar un comando**. Para la instalación de
`ojito.top` con PHP 8.3, usa exactamente:

```cron
7 * * * * /opt/plesk/php/8.3/bin/php /var/www/vhosts/ojito.top/httpdocs/bin/poll-airlabs.php >> /var/www/vhosts/ojito.top/httpdocs/storage/logs/cron.log 2>&1
*/5 * * * * /opt/plesk/php/8.3/bin/php /var/www/vhosts/ojito.top/httpdocs/bin/poll-opensky.php >> /var/www/vhosts/ojito.top/httpdocs/storage/logs/cron.log 2>&1
*/10 * * * * /opt/plesk/php/8.3/bin/php /var/www/vhosts/ojito.top/httpdocs/bin/poll-weather.php >> /var/www/vhosts/ojito.top/httpdocs/storage/logs/cron.log 2>&1
```

Los nombres `sync-week.php`, `sync-near.php` y `sync-live.php` no pertenecen a
esta versión y no deben añadirse como tareas. `bin/sync-json.php` sí forma parte
del repositorio, pero es una herramienta manual de importación y no necesita
cron.

OpenSky se consulta solamente para vuelos próximos con matrícula/ICAO24
conocido. AirLabs `/flights` completa esos identificadores cuando están
publicados y su posición en vivo actúa como respaldo hasta recibir una lectura
OpenSky. Todas las matrículas se envían en una única petición y la respuesta
incluye la cuota restante cuando OpenSky publica esa cabecera. AirLabs es una
fuente secundaria y se recomienda ejecutarlo una vez por hora para conservar
cuota. Las observaciones introducidas como Aena tienen prevalencia en sala,
cinta y estado.

Una cinta comunicada por AirLabs se conserva y aparece en la cronología como
provisional, incluso si el proveedor no informa la sala. Si discrepa de Aena no
sustituye la cinta oficial ni se contabiliza como un cambio operativo de Aena:
la interfaz muestra ambas evidencias y señala expresamente la discrepancia.
En el tablero principal la propuesta secundaria aparece debajo de la cinta
oficial: amarilla mientras espera confirmación, verde cuando coincide y gris
cuando una observación posterior de Aena no la confirma. Las lecturas OpenSky
consecutivas que no cambian ningún dato visible se agrupan en un solo tramo.
El filtro **Cintas API** permite aislar los vuelos con propuesta, confirmación o
descarte secundario sin recorrer todo el listado.

### Tablero visual y radar ADS-B

La versión 1.6 mantiene una banda independiente con todos los vuelos de
Canarias: no desaparecen al aplicar filtros en la tabla principal. Al abrir el
día actual, el navegador baja una sola vez hasta la primera llegada vigente;
el botón **Ir a ahora** repite el salto cuando sea necesario.

El mapa utiliza Leaflet y cartografía OpenStreetMap. Las aeronaves aparecen
cuando OpenSky o AirLabs en vivo han guardado una posición para el vuelo; la
ficha identifica expresamente cuál de las dos fuentes entregó la última señal.
Al pulsar un avión se muestran tipo, matrícula, altura, velocidad, rumbo,
antigüedad de la señal y destino de equipaje. Una señal de más de diez minutos
se representa atenuada. La silueta es una representación del tipo, no una
fotografía de la matrícula.

La infografía **Flujo del aeropuerto** clasifica automáticamente los vuelos de
la ventana activa en prevista, en ruta, aterrizando, en tierra y en cintas. La
clasificación usa telemetría y estados publicados; puerta, posición, finger y
cinta se muestran como pendientes si ninguna fuente los ha facilitado. Aena
continúa prevaleciendo para hora, estado, sala y cinta.

La versión 1.7 añade una escena HTML5/SVG animada sobre ese flujo: aproximación,
pista, rodaje, terminal y cintas. Cada avión se coloca en la fase operativa
deducible de los datos, los canarios quedan resaltados y cualquier aeronave se
puede pulsar para abrir su ficha. Es un esquema de situación, no un plano de
puestos; por eso nunca convierte una posición pendiente en finger o remoto.

La versión 1.8 amplía esa escena y representa las ocho cintas de la planta 0 en
el orden operativo indicado para el proyecto: **8–1 de izquierda a derecha**,
equivalente a **1–8 de derecha a izquierda**. Encima aparece una línea con las
cinco próximas llegadas, siempre con vuelo, procedencia, hora efectiva y cinta
oficial o `pendiente Aena`. El radar ADS-B queda plegado por defecto y se abre
solo cuando el usuario lo necesita.

La versión 1.9 convierte la escena en un panel operativo centrado en cintas. El
nombre del origen aparece en grande dentro de cada cinta; las posiciones y las
trazas solo avanzan cuando una fuente telemétrica aporta nuevas coordenadas. Cuando no
existe señal, el vuelo se conserva como previsto sin simular una posición. Las
líneas verdes terminal–cinta representan el recorrido conceptual del equipaje,
no el rodaje del avión.

Desde la versión 1.12 el listado principal queda plegado por defecto y contiene
el día completo. Los vuelos anteriores se muestran atenuados en gris y los que
siguen activos conservan todo el contraste. Cada vuelo muestra la media histórica de antelación de la
primera cinta oficial para el mismo número de vuelo y para el mismo origen,
junto con sus tamaños de muestra. Un panel independiente reúne los cambios de
cinta oficiales; la ficha explica el cambio publicado y separa expresamente el
contexto observado de cualquier causa no demostrada.

La versión 1.10 añade el perfil histórico de cintas al abrir un vuelo. Para
cada ejecución pasada se toma la última cinta oficial de Aena y se presenta el
porcentaje y el número de casos en las cintas 1–8. En vuelos canarios se muestra
además el perfil agregado del aeropuerto de origen. La propensión a 7/8 sigue
la matriz: menos de cinco observaciones es histórico insuficiente, más del 50 %
es caliente confirmado y desde el 70 % es caliente fuerte.

La versión 1.11 rehace la infografía de movimiento en cuatro niveles: cielo,
pista 09/27, plataforma con las seis pasarelas telescópicas documentadas por
Aena y las ocho cintas. Los vuelos en el cielo se limitan a los cinco más
próximos y se ordenan por distancia ADS-B; cuando no existe telemetría reciente
se muestran como previstos y no se inventa su lado de entrada. Las coordenadas,
rumbo, altura y distancia sitúan el marcador y determinan si la aproximación se
produce por el oeste (09) o por el este (27). Los nodos se conservan entre
capturas y se desplazan mediante transición continua, con un margen de 20
segundos antes de ocultar una lectura ausente para evitar parpadeos.

Al llegar a tierra, la escena diferencia puerta, puesto de estacionamiento y
pasarela: una puerta publicada no se convierte automáticamente en un finger.
El tramo verde hacia las cintas cambia el icono a equipaje y representa el
flujo del vuelo/pasajeros, nunca el rodaje físico de la aeronave dentro del
terminal. Las referencias físicas proceden del [AIP oficial de
ENAIRE](https://aip.enaire.es/AIP/contenido_AIP/AD/AD2/LEZL/LE_AD_2_LEZL_es.html)
y de la información de [Aena sobre las seis pasarelas de
Sevilla](https://www.aena.es/es/prensa/el-aeropuerto-de-sevilla-adjudica-por-mas-de-cinco-millones-la-instalacion---de-seis-pasarelas-de-embarque-de-ultima-generacion.html%26p%3D1575078740846).

La corrección 1.11.1 separa visualmente la asignación de la entrega: una maleta
con origen y vuelo espera en una franja situada encima de su cinta mientras
Aena solo la mantiene asignada. El recuadro inferior queda marcado como
`ESPERA ARRIBA` y no muestra el vuelo como si ya estuviera entregando. Cuando el
estado pasa a entrega de equipaje, la maleta desaparece de la espera y el vuelo
ocupa el recuadro de la cinta. Ningún marcador invade los botones.

La corrección 1.11.2 amplía la escena a 800 px y aplica la secuencia operativa
completa: el marcador continúa siendo un avión mientras está previsto, en ruta
o aproximando; al confirmarse el aterrizaje se transforma en una maleta y se
desplaza progresivamente hasta quedar encima de su cinta; al comenzar la
entrega, desaparece de la espera y ocupa el botón. Por cada cinta solo se
muestran el vuelo actual y, si existe, el siguiente aterrizado en espera. Las
entregas con más de 45 minutos dejan de contaminar la vista aunque una fuente
mantenga temporalmente un estado antiguo.

La corrección 1.11.3 ordena cada cola por tiempo real: la entrega más antigua
ocupa el recuadro inferior y la siguiente llegada queda encima; si aún no hay
entrega publicada, pueden verse como máximo dos maletas pendientes, con la más
antigua abajo y la más nueva arriba. Una cinta de Aena ya es una asignación
oficial, pero no equivale a que la descarga haya comenzado: el vuelo solo baja
al recuadro cuando Aena informa entrega de equipaje. En móvil vertical, la
escena separa el aeródromo y dispone las ocho cintas en dos columnas, evitando
forzar la orientación y mostrando el siguiente vuelo dentro de la propia cinta.

La corrección 1.11.5 amplía la infografía a 1.080 px en escritorio y crea una
franja independiente de equipajes en tránsito entre la terminal y las cintas.
La maleta más antigua queda en el nivel inferior y la siguiente, más reciente,
en el superior; las cintas aumentan su altura y dejan de mezclarse visualmente
con plataforma, fingers y aeronaves. En móvil vertical la escena crece a 1.580
px y reserva 830 px para las ocho cintas en dos columnas.

La corrección 1.11.6 ensancha la terminal hasta prácticamente la longitud de la
pista y distribuye las seis pasarelas de extremo a extremo. Los carteles usan
ahora una anchura fluida limitada, los cinco vuelos previstos se reparten con
espacio suficiente, las aproximaciones se apilan en
vertical por cada cabecera y las maletas de cintas contiguas alternan su altura.
Esto evita que las fichas se pisen tanto en escritorio como en móvil apaisado.

La corrección 1.11.7 compacta las observaciones idénticas de Aena, AirLabs,
OpenSky y cualquier otra fuente por separado: la primera lectura permanece
visible y las repeticiones se resumen como `+1`, `+2`, etc. La cola operativa
superior y el cielo muestran los cinco vuelos siguientes y se reponen en cada
refresco cuando uno aterriza. Los vuelos finalizados salen de la escena y su
cinta vuelve a libre. También se amplía el corredor situado bajo la terminal
para separar mejor las maletas de las posiciones de plataforma.

La corrección 1.11.9 adapta la carga semanal al comportamiento real del
calendario de Infovuelos: un día futuro se expresa como un intervalo desde hoy
hasta la fecha elegida, mientras que el modo en directo cierra hoy con un
segundo clic. El recolector lee una sola vez el intervalo completo, obtiene la
fecha real de cada vuelo desde los separadores diarios de Aena y evita duplicar
o atribuir al día equivocado las filas futuras. El intervalo se reintenta hasta
tres veces ante un fallo transitorio. Los cambios del propio recolector disparan
también una prueba semanal completa en `main`, además de la ejecución diaria y
del botón manual de GitHub Actions. La versión 1.11.10 acota además cada clic al
panel del mes exacto para que los días repetidos del mes vecino no intercepten
la selección cuando la semana cruza de septiembre a octubre. La versión 1.11.11
consulta el intervalo cronológico completo y usa la paginación oficial **Ver
más** hasta que el número de filas cargadas coincide exactamente con el total
publicado por Aena; así no interpreta de forma errónea las horas como franjas
independientes de cada día ni pierde vuelos en una semana con cientos de filas.
La versión 1.11.13 corrige la transición visual de equipajes: reconoce tanto
`Entrega equipaje` como `Entrega de equipajes`, coloca el vuelo dentro de su
cinta mientras la recogida está activa y lo retira cuando Aena lo finaliza. La
asignación previa permanece arriba como pendiente y no ocupa la cinta.

La versión 1.11.12 conserva la clave histórica habitual y añade la hora
programada únicamente cuando Aena publica dos operaciones con el mismo número,
origen y fecha, evitando que vuelos legítimos como los dos RYR2200 del 29 de
septiembre choquen durante la conciliación.

Los vuelos canarios tienen avisos emergentes prioritarios. Cada actualización
del tablero compara cinta/sala, horario, ETA, estado, llegada, equipaje, puerta,
posición, aeronave y propuestas secundarias con la lectura anterior guardada en
el navegador. La corrección 1.11.4 muestra un único aviso cada vez: dura 60
segundos si no hay otro esperando y 30 segundos si existe cola. Los cambios del
mismo vuelo se fusionan en ese aviso y las notificaciones del navegador siguen
la misma secuencia, evitando acumulaciones. El botón **Activar avisos** permite
habilitarlas mientras la web esté abierta.

La futura carga semanal utilizará este mismo reconciliador. La periodicidad
recomendada es: siete días completos una vez al día, hoy y mañana cada 30
minutos y la ventana operativa próxima cada 2-5 minutos. Los datos vivos deben
entrar directamente en MySQL desde el servidor; GitHub conserva el código y las
migraciones, no se usa como almacén de capturas diarias.

La versión 1.12.0 abre el radar a la telemetría AirLabs como respaldo y usa la
matrícula/ICAO24 obtenida allí para alimentar las consultas posteriores a
OpenSky. El listado principal pasa a ser un panel plegado de día completo:
contiene todos los vuelos que devuelve el tablero y atenúa en gris los que ya
han quedado atrás, manteniendo resaltados los movimientos todavía activos. Las
antelaciones de cinta conservan el valor exacto en minutos y, desde 60 minutos,
añaden su equivalencia en horas (`840 min · 14 h`).

La versión 1.13.0 incorpora en **Procesos web** un panel de salud real para
Aena, AirLabs, OpenSky y AviationWeather. Cada fuente queda clasificada como al
día, retrasada, con error, sin datos o sin configurar; muestra último intento,
último éxito, próxima ejecución esperada, registros e intentos del día. El
consumo mensual se presenta como recuento o estimación local y nunca como cuota
oficial cuando el proveedor no comunica el saldo. Los errores `429`, límites y
cuotas quedan destacados para separar una fuente vacía de una fuente bloqueada.

La versión 1.14.0 activa el **cerebro predictivo medible**. Cuando una operación
todavía no tiene cinta oficial, conserva una única predicción previa basada en
el histórico del mismo vuelo, el origen y, si existe, la propuesta secundaria.
La predicción nunca sustituye a Aena: queda rotulada como modelo histórico y se
contrasta después con la cinta oficial. El tablero muestra su confianza, el
resultado de cada caso y el porcentaje acumulado de acierto general y de
Canarias. De este modo se mide el modelo sin reescribir predicciones a posteriori.

La versión 1.15.0 añade recuperación operativa y protección de datos. La salud
de Aena usa su última conciliación en directo y separa el estado semanal; las
cadencias de Plesk son configurables y OpenSky entra en **EN PAUSA** mientras
respeta el `Retry-After` de un 429. Un workflow de respaldo solo ejecuta una
fuente si no existe una lectura reciente. La carga semanal repite un ciclo
completo ante una indisponibilidad transitoria de Infovuelos.

El administrador incorpora copias MySQL comprimidas, checksum, validación y
retención 7/5/12. Web Push permite vigilar expresamente cada vuelo y solo envía
los cinco hitos configurados para esas operaciones. El acceso
administrativo limita cinco intentos fallidos por quince minutos y conserva un
registro técnico sin contraseñas ni direcciones IP en claro.

La versión 1.16.0 separa en las tarjetas permanentes de Canarias la **predicción
de la matriz** de la **cinta confirmada por Aena**. Una propuesta de AirLabs se
mantiene como indicio secundario. `STAND BY` pasa a llamarse `CINTA PENDIENTE ·
Aena`: no describe una espera del avión, sino la ausencia del dato oficial.

También incorpora el análisis diario de los cambios de cinta del día anterior.
Cada cambio oficial queda asociado a una causa publicada o, si no existe, a un
motivo inferido con nivel de confianza y evidencia: desviación, solapamiento en
la cinta previa, presión simultánea de sala o cambio de zona A/B. Las
inferencias no afirman causalidad. El historial se consulta y puede ejecutarse
desde **Administración → Motivos de cinta** después de aplicar el paquete MySQL
`20260930_006_belt_change_analysis`. El tablero lo ejecuta una vez al día a
partir de las 04:00; opcionalmente Plesk puede llamar a
`bin/analyze-belt-changes.php` a las 04:20. Los vuelos con cambio confirmado o
con una cinta oficial anormalmente tardía quedan destacados en rojo.

La versión 1.16.1 corrige la distinción entre una causa realmente publicada y
el texto técnico de una captura de Infovuelos. Si Aena no publica una causa, el
sistema lo presenta expresamente como hipótesis no confirmada y conserva las
evidencias que la sustentan. Los casos críticos abiertos activan una captura de
Infovuelos cada 5 minutos hasta que el equipaje finaliza; sin casos críticos se
mantiene la cadencia normal aproximada de 15 minutos. El endpoint protegido
`public/api/aena-control.php` decide la cadencia utilizando el mismo
`AENA_INGEST_TOKEN` del recolector.

La versión 1.16.2 corrige la identidad de llegadas con código compartido. Si
Infovuelos muestra primero un código IBE pero la misma llegada incluye VLG,
IBS o ANE, el sistema conserva todos los códigos y utiliza como vuelo físico el
operador conocido. La conciliación Aena también puede recuperar un registro
creado anteriormente bajo el código compartido, evitando una segunda llegada
fantasma y el falso estado de cinta pendiente.

La versión 1.16.4 corrige la conciliación MySQL de alias de código compartido
utilizando parámetros únicos en consultas preparadas nativas.

La versión 1.16.5 muestra expresamente en las tarjetas prioritarias de Canarias
los códigos compartidos asociados al vuelo físico, sin recrearlos como vuelos
independientes.

La versión 1.16.3 oculta además del tablero cualquier duplicado histórico IBE
cuando ya existe la llegada física VLG, IBS o ANE enlazada por código, origen y
proximidad horaria. El registro y sus evidencias no se borran de la base.

## Seguridad

- El directorio público del dominio debe ser `public/`, nunca la raíz del proyecto.
- No subas `.env` a GitHub.
- No introduzcas claves API en JavaScript.
- Usa HTTPS en producción.
- Cambia la contraseña de administración antes de publicar.
- Si Plesk mantiene `httpdocs` como raíz y la aplicación se abre con `/public`,
  conserva el `.htaccess` de la raíz: impide servir por HTTP `.env`, `storage`,
  `bin`, `src`, `database`, los backups y las claves VAPID.

## Estados de la matriz

- `🟢` cinta consolidada.
- `🟠` desviación ≥15 min, cambio reciente, inversión o intervalo ≤30 min.
- `🔵` en vuelo.
- `🟠🟢` entrega de equipaje bajo revisión.
- `🟢✓⁺` finalizado sin nuevos cambios.
- `🔴7` y `🔴8` indican ubicación operativa, no una valoración del vuelo.

## Estructura

- `public/`: tablero, administración y endpoints JSON/SSE.
- `src/`: acceso a datos, seguridad, proveedores y reglas.
- `src/Sync/`: conciliación, eventos y lectura de ejecuciones.
- `bin/`: recopiladores ejecutados por cron.
- `database/`: estructura y datos de demostración.
- `storage/`: caché OAuth y registros locales.
