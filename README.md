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

AirLabs se consulta mediante el tablero agrupado de llegadas a SVQ: una única
petición devuelve hasta 50 movimientos y la aplicación los concilia localmente
por ruta, fecha, vuelo físico y códigos compartidos. El `.env` admite
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

`bin/sync-json.php` es exclusivamente de consola. Para importar el mismo
contrato JSON sin terminal, entra en **Administración → Procesos web → Importar
captura JSON**. El endpoint web exige sesión administrativa y CSRF, limita el
lote y lo envía al mismo conciliador.

Desde **Administración → Cerebro de eventos** puede activarse **Avisos web**.
El navegador consulta cada 30 segundos y notifica únicamente eventos materiales
nuevos (cinta, sala, ETA, cancelación, puerta, posición y equipaje) mientras la
página permanezca abierta o en segundo plano. No repite eventos ya vistos.

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
conocido. Todas las matrículas se envían en una única petición y la respuesta
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

El mapa utiliza Leaflet y cartografía OpenStreetMap. Las aeronaves solo aparecen
cuando OpenSky ha guardado una posición ADS-B para un ICAO24 conocido. Al pulsar
un avión se muestran tipo, matrícula, altura, velocidad, rumbo, antigüedad de la
señal y destino de equipaje. Una señal de más de diez minutos se representa
atenuada. La silueta es una representación del tipo, no una fotografía de la
matrícula.

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
diez próximas llegadas, siempre con vuelo, procedencia, hora efectiva y cinta
oficial o `pendiente Aena`. El radar ADS-B queda plegado por defecto y se abre
solo cuando el usuario lo necesita.

La versión 1.9 convierte la escena en un panel operativo centrado en cintas. El
nombre del origen aparece en grande dentro de cada cinta; las posiciones y las
trazas solo avanzan cuando OpenSky aporta nuevas coordenadas ADS-B. Cuando no
existe señal, el vuelo se conserva como previsto sin simular una posición. Las
líneas verdes terminal–cinta representan el recorrido conceptual del equipaje,
no el rodaje del avión.

El listado principal se reduce a cinco vuelos anteriores, hasta cinco activos
en una ventana de ±60 minutos y cinco posteriores, con carga incremental de
cinco en cinco. Cada vuelo muestra la media histórica de antelación de la
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
Aena y las ocho cintas. Los vuelos en el cielo se limitan a los diez más
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

Los vuelos canarios tienen avisos emergentes prioritarios. Cada actualización
del tablero compara cinta/sala, horario, ETA, estado, llegada, equipaje, puerta,
posición, aeronave y propuestas secundarias con la lectura anterior guardada en
el navegador. El aviso interno permanece hasta cerrarlo; el botón **Activar
avisos** permite además habilitar notificaciones del navegador mientras la web
esté abierta.

La futura carga semanal utilizará este mismo reconciliador. La periodicidad
recomendada es: siete días completos una vez al día, hoy y mañana cada 30
minutos y la ventana operativa próxima cada 2-5 minutos. Los datos vivos deben
entrar directamente en MySQL desde el servidor; GitHub conserva el código y las
migraciones, no se usa como almacén de capturas diarias.

## Seguridad

- El directorio público del dominio debe ser `public/`, nunca la raíz del proyecto.
- No subas `.env` a GitHub.
- No introduzcas claves API en JavaScript.
- Usa HTTPS en producción.
- Cambia la contraseña de administración antes de publicar.

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
