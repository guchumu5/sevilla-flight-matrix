<?php
declare(strict_types=1);

define('PROJECT_ROOT', dirname(__DIR__));

spl_autoload_register(static function (string $class): void {
    $prefix = 'SevillaMatrix\\';
    if (!str_starts_with($class, $prefix)) return;
    $relative = substr($class, strlen($prefix));
    $path = PROJECT_ROOT . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) require $path;
});

SevillaMatrix\Env::load(PROJECT_ROOT . '/.env');
date_default_timezone_set(SevillaMatrix\Env::get('APP_TIMEZONE', 'Europe/Madrid') ?? 'Europe/Madrid');

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

