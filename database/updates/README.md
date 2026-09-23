# Paquetes de actualización de MySQL

Cada fichero PHP de este directorio registra un paquete SQL que aparecerá en
**Administración → Actualizar MySQL**.

## Reglas

1. El identificador y el nombre del fichero deben ser únicos y ordenables.
2. Una actualización aplicada no se modifica; cualquier corrección se publica
   como un paquete nuevo.
3. El SQL debe ser idempotente siempre que sea posible.
4. El fichero SQL debe permanecer dentro de `database/`.
5. Las actualizaciones con `ALTER TABLE` deben marcarse como no
   transaccionales porque MySQL realiza confirmaciones implícitas.
6. Nunca se incluyen contraseñas, claves API ni datos del archivo `.env`.

## Plantilla

```php
<?php
declare(strict_types=1);

return [
    'id' => 'AAAAMMDD_NNN_descripcion',
    'name' => 'Nombre visible',
    'description' => 'Qué incorpora o corrige.',
    'category' => 'Datos, estructura o motor',
    'sql_file' => dirname(__DIR__) . '/mi_actualizacion.sql',
    'transactional' => true,
    'requires_backup' => true,
];
```

El gestor calcula la huella SHA-256 del SQL, bloquea ejecuciones simultáneas y
registra el resultado en `schema_migrations`.
