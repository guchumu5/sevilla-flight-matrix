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

Los números publicados por Aena suelen usar prefijos ICAO de tres letras
(`VLG`, `RYR`, `BAW`, `IBE`, etc.). El cliente AirLabs los consulta mediante
`flight_icao`; los códigos de dos caracteres (`VY`, `FR`, `BA`, `IB`, etc.) se
consultan mediante `flight_iata`. Si el vuelo físico no devuelve información,
la aplicación prueba también sus códigos compartidos y deja constancia de todos
los códigos intentados.

AirLabs ya entra por el mismo conciliador que el importador JSON: conserva cada
instantánea y genera los cambios correspondientes en el cerebro. Las
observaciones de Aena introducidas en el formulario administrativo también
generan eventos. OpenSky añade telemetría ADS-B y AviationWeather guarda el
METAR, que son evidencias complementarias y no sustituyen la autoridad de Aena.

Los scripts de `bin/` se conservan para automatizar desde el panel web de Plesk
o cPanel. No deben hacerse accesibles como direcciones web.

En Plesk, crea tareas de tipo **Ejecutar un comando**. Para la instalación de
`ojito.top` con PHP 8.3, usa exactamente:

```cron
*/10 * * * * /opt/plesk/php/8.3/bin/php /var/www/vhosts/ojito.top/httpdocs/bin/poll-airlabs.php >> /var/www/vhosts/ojito.top/httpdocs/storage/logs/cron.log 2>&1
*/5 * * * * /opt/plesk/php/8.3/bin/php /var/www/vhosts/ojito.top/httpdocs/bin/poll-opensky.php >> /var/www/vhosts/ojito.top/httpdocs/storage/logs/cron.log 2>&1
*/10 * * * * /opt/plesk/php/8.3/bin/php /var/www/vhosts/ojito.top/httpdocs/bin/poll-weather.php >> /var/www/vhosts/ojito.top/httpdocs/storage/logs/cron.log 2>&1
```

Los nombres `sync-week.php`, `sync-near.php` y `sync-live.php` no pertenecen a
esta versión y no deben añadirse como tareas. `bin/sync-json.php` sí forma parte
del repositorio, pero es una herramienta manual de importación y no necesita
cron.

OpenSky se consulta solamente para vuelos con matrícula/ICAO24 conocido. AirLabs es una fuente secundaria. Las observaciones introducidas como Aena tienen prevalencia en sala, cinta y estado.

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
