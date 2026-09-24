# Guía de instalación paso a paso

## Opción A · Probar en un ordenador con XAMPP

1. Instala XAMPP con PHP 8.1 o superior y MySQL.
2. Copia la carpeta `sevilla-flight-matrix` dentro de `xampp/htdocs/`.
3. Inicia **Apache** y **MySQL** desde el panel de XAMPP.
4. Abre `http://localhost/phpmyadmin`.
5. Crea una base de datos llamada `sevilla_matrix` con cotejamiento `utf8mb4_unicode_ci`.
6. Selecciona la base, pulsa **Importar** y carga `database/schema.sql`.
7. Para ver datos de prueba, importa después `database/demo.sql`.
8. Copia `.env.example` y renómbralo `.env`.
9. En `.env`, configura:

   ```env
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_NAME=sevilla_matrix
   DB_USER=root
   DB_PASS=
   ```

10. Genera la contraseña administrativa desde una consola situada en la carpeta de XAMPP:

    ```bash
    php -r "echo password_hash('TuContraseñaSegura', PASSWORD_DEFAULT), PHP_EOL;"
    ```

11. Copia el resultado completo después de `ADMIN_PASSWORD_HASH=`.
12. Abre `http://localhost/sevilla-flight-matrix/public/`.

## Opción B · Servidor o alojamiento con cPanel

1. Crea una base MySQL y un usuario desde cPanel.
2. Asigna al usuario todos los permisos sobre esa base.
3. Importa `database/schema.sql` desde phpMyAdmin.
4. Sube el proyecto fuera de `public_html` cuando el proveedor lo permita.
5. Configura el dominio o subdominio para que su raíz apunte a la carpeta `public`.
6. Copia `.env.example` como `.env` y escribe los datos MySQL del proveedor.
7. Activa HTTPS.
8. Configura las tareas cron descritas en `README.md`.

Si el alojamiento no permite elegir la raíz pública, coloca solamente el contenido de `public/` en `public_html` y pide al proveedor que configure una ruta segura hacia `src/`. No copies `.env` dentro de una carpeta públicamente accesible.

## Alta en AirLabs

1. Entra en `https://airlabs.co/`.
2. Pulsa **Get FREE API Key**.
3. Registra una cuenta y verifica el correo electrónico.
4. En el panel de la cuenta, copia la API key.
5. Pégala únicamente en `.env`:

   ```env
   AIRLABS_API_KEY=tu_clave
   ```

6. Entra en **Administración → Procesos web** y pulsa **Actualizar AirLabs** para hacer la primera prueba.

AirLabs es una fuente secundaria. Si su cinta discrepa con Aena, entra en **Aena / Admin** y registra la observación oficial.

## Alta en OpenSky

1. Entra en `https://opensky-network.org/` y crea una cuenta.
2. Verifica el correo e inicia sesión.
3. Abre la página **Account**.
4. Crea un nuevo cliente API.
5. Copia `client_id` y `client_secret` en `.env`:

   ```env
   OPENSKY_CLIENT_ID=tu_client_id
   OPENSKY_CLIENT_SECRET=tu_client_secret
   ```

6. La aplicación renovará automáticamente el token OAuth2.
7. Para que un vuelo se siga por ADS-B debe tener su `aircraft_icao24` registrado en MySQL. No es la matrícula: es el identificador hexadecimal del transpondedor.

## AviationWeather

No requiere una clave. En **Administración → Procesos web** pulsa **Actualizar tiempo** para consultar el METAR de `LEZL` y guardar viento, rachas y observación completa.

## Primera puesta en marcha

1. Entra en `/admin.php`.
2. Añade los vuelos físicos del día. El código compartido se introduce aparte.
3. Abre **Procesos web**, comprueba que AirLabs aparece como `LISTO` y ejecútalo.
4. Confirma manualmente en el panel las cintas publicadas por Aena.
5. Abre el tablero desde un móvil y comprueba filtros, histórico y avisos.
6. Cuando todo funcione, borra los datos de demostración antes de introducir vuelos reales.

## Actualizar MySQL desde la aplicación

1. Descarga o despliega la versión nueva completa del repositorio.
2. Accede a `/admin.php` con la contraseña administrativa.
3. Pulsa **Actualizar MySQL**.
4. Revisa la descripción, categoría, huella y número de sentencias del paquete.
5. Confirma que dispones de copia de seguridad o que aceptas aplicar el cambio.
6. Pulsa **Aplicar** y espera el mensaje de finalización sin cerrar la página.

El usuario configurado en `DB_USER` necesita permisos `CREATE`, `ALTER`,
`INDEX`, `SELECT`, `INSERT` y `UPDATE` sobre su propia base. El panel no permite
subir ni escribir SQL: solo ejecuta paquetes versionados que formen parte del
proyecto. Cada ejecución queda registrada en `schema_migrations`.

HTTPS no es necesario para una prueba estrictamente local, pero sí debe
activarse antes de exponer el administrador en Internet, porque protege la
contraseña y la cookie de sesión durante el transporte.

## Activar el cerebro de eventos

1. Despliega la versión 1.2 completa.
2. Entra en **Administración → Actualizar MySQL**.
3. Aplica `20260923_002_event_brain` después de cualquier paquete anterior pendiente.
4. Abre **Administración → Cerebro de eventos**. Al principio aparecerá vacío.
5. Para una prueba técnica opcional, el contrato JSON continúa disponible desde consola:

   ```bash
   php bin/sync-json.php --file=database/examples/sync_flights.example.json
   php bin/sync-json.php --file=database/examples/sync_flights.changed.example.json
   ```

6. Actualiza la pantalla del cerebro: verás la ejecución, la primera aparición,
   el estado y la primera asignación de sala/cinta.

El importador JSON es el contrato común entre las fuentes y la base. Cuando se
conecte una API o un adaptador de Aena, este producirá el mismo formato y llamará
al mismo reconciliador; las reglas de historial no dependerán del proveedor.

Para una carga completa se deben indicar `mode=full_window`, los límites de la
ventana y `complete=true`. El sistema no elimina un vuelo por una sola ausencia:
lo marca como `missing` y solo genera `flight_withdrawn` después de tres ventanas
completas consecutivas. Si reaparece, genera `flight_reappeared`.

## Plesk en ojito.top · trabajo sin terminal

Con el proyecto instalado en `/var/www/vhosts/ojito.top/httpdocs`, no necesitas
abrir una consola para el uso diario:

1. Despliega la versión 1.4 completa.
2. Entra en la aplicación y abre **Administración → Actualizar MySQL**. Aplica
   las actualizaciones pendientes.
3. Vuelve a Administración y abre **Procesos web**.
4. Comprueba que MySQL, cURL, caché, tablas y proveedores aparecen en verde.
5. Pulsa **Actualizar todo** para una captura limitada, o ejecuta cada proveedor
   por separado para ver su resultado.
6. Abre **Cerebro de eventos**: una respuesta válida de AirLabs o una observación
   manual de Aena deberá crear una ejecución; solo habrá un evento nuevo si el
   valor realmente cambió.

Para automatizar sin terminal, entra en **Plesk → Dominios → ojito.top → Tareas
programadas → Añadir tarea** y selecciona **Ejecutar un comando**. Crea estas
tres tareas:

| Estilo cron | Comando |
|---|---|
| `*/10 * * * *` | `/opt/plesk/php/8.3/bin/php /var/www/vhosts/ojito.top/httpdocs/bin/poll-airlabs.php >> /var/www/vhosts/ojito.top/httpdocs/storage/logs/cron.log 2>&1` |
| `*/5 * * * *` | `/opt/plesk/php/8.3/bin/php /var/www/vhosts/ojito.top/httpdocs/bin/poll-opensky.php >> /var/www/vhosts/ojito.top/httpdocs/storage/logs/cron.log 2>&1` |
| `*/10 * * * *` | `/opt/plesk/php/8.3/bin/php /var/www/vhosts/ojito.top/httpdocs/bin/poll-weather.php >> /var/www/vhosts/ojito.top/httpdocs/storage/logs/cron.log 2>&1` |

Si el plan gratuito de una API tiene poca cuota, aumenta el intervalo de su
tarea antes de activarla. Primero prueba cada botón en **Procesos web**: si un
proveedor falla allí, la tarea programada también fallará. Los scripts usan el
mismo servicio que los botones, de modo que no existen dos lógicas distintas.

La carpeta pública del dominio debe seguir apuntando a `httpdocs/public` siempre
que Plesk lo permita. `src/`, `bin/`, `database/`, `storage/` y `.env` no deben
servirse directamente por HTTP.

### Activar las cintas Aena

Las cintas no proceden de esos tres cron. El recolector de Infovuelos se ejecuta
en GitHub Actions porque la página oficial necesita un navegador. Haz lo siguiente:

1. Pon en el `.env` del servidor una cadena aleatoria de al menos 24 caracteres:
   `AENA_INGEST_TOKEN=TU_CADENA`.
2. En GitHub abre **Settings → Secrets and variables → Actions** y crea el
   secreto `MATRIX_INGEST_TOKEN` con exactamente la misma cadena.
3. Abre **Actions → Aena en directo → Run workflow**. La primera ejecución
   manual permite verificarlo; después se repetirá cada 15 minutos. La acción
   **Aena programación semanal** actualizará diariamente los siete días sin
   añadir ningún cron en Plesk.

No crees cron llamados `sync-week.php`, `sync-near.php` o `sync-live.php`: no
existen. `bin/sync-json.php` sí existe en el repositorio, pero solo sirve para
importaciones manuales y pruebas del conciliador.

## Comprobaciones básicas

- `/api/health.php` debe responder `ok: true`.
- El tablero debe actualizar su hora cada 15 segundos.
- La contraseña de administración nunca debe aparecer en el código.
- `.env` no debe subirse a GitHub ni enviarse por correo.
- La hora del servidor debe estar configurada en `Europe/Madrid`.
