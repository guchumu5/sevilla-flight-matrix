# Matriz Operativa de Cintas · Sevilla

Aplicación web PHP/MySQL para vigilar las llegadas físicas a Sevilla, registrar el historial de sala/cinta y aplicar la matriz operativa acordada.

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

## Automatización

Configura estas tareas cron. Ajusta las rutas a tu servidor:

```cron
*/2 * * * * /usr/bin/php /ruta/sevilla-flight-matrix/bin/poll-airlabs.php >> /ruta/sevilla-flight-matrix/storage/logs/cron.log 2>&1
* * * * * /usr/bin/php /ruta/sevilla-flight-matrix/bin/poll-opensky.php >> /ruta/sevilla-flight-matrix/storage/logs/cron.log 2>&1
*/10 * * * * /usr/bin/php /ruta/sevilla-flight-matrix/bin/poll-weather.php >> /ruta/sevilla-flight-matrix/storage/logs/cron.log 2>&1
```

OpenSky se consulta solamente para vuelos con matrícula/ICAO24 conocido. AirLabs es una fuente secundaria. Las observaciones introducidas como Aena tienen prevalencia en sala, cinta y estado.

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
- `bin/`: recopiladores ejecutados por cron.
- `database/`: estructura y datos de demostración.
- `storage/`: caché OAuth y registros locales.
