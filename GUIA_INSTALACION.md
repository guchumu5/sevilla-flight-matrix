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

6. Ejecuta manualmente `php bin/poll-airlabs.php` para hacer la primera prueba.

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

No requiere una clave. `bin/poll-weather.php` consulta el METAR de `LEZL` y guarda viento, rachas y observación completa.

## Primera puesta en marcha

1. Entra en `/admin.php`.
2. Añade los vuelos físicos del día. El código compartido se introduce aparte.
3. Ejecuta el recopilador AirLabs o espera a la tarea cron.
4. Confirma manualmente en el panel las cintas publicadas por Aena.
5. Abre el tablero desde un móvil y comprueba filtros, histórico y avisos.
6. Cuando todo funcione, borra los datos de demostración antes de introducir vuelos reales.

## Comprobaciones básicas

- `/api/health.php` debe responder `ok: true`.
- El tablero debe actualizar su hora cada 15 segundos.
- La contraseña de administración nunca debe aparecer en el código.
- `.env` no debe subirse a GitHub ni enviarse por correo.
- La hora del servidor debe estar configurada en `Europe/Madrid`.

