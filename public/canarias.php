<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';

use SevillaMatrix\OperationalDay;

$today = OperationalDay::current();
$night = (string)($_GET['night'] ?? '') === '1';
$week = (string)($_GET['week'] ?? '') === '1';
$origin = strtoupper(preg_replace('/[^A-Za-z]/', '', (string)($_GET['origin'] ?? '')));
if (strlen($origin) > 3) $origin = substr($origin, 0, 3);
$date = trim((string)($_GET['date'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = $today;
$nightFrom = max(18, min(23, (int)($_GET['night_from'] ?? 22)));
$cssVersion = (string)(@filemtime(__DIR__ . '/assets/css/canarias.css') ?: '1');
$jsVersion = (string)(@filemtime(__DIR__ . '/assets/js/canarias.js') ?: '1');

$filters = [];
if ($week) $filters[] = 'Semana';
if ($night) $filters[] = sprintf('Noche %02d:00–01:59', $nightFrom);
if ($origin !== '') $filters[] = $origin;
$title = 'Canarias' . ($filters ? ' · ' . implode(' · ', $filters) : '');
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="description" content="Consulta Canarias: ocupación de cintas y vuelos de alrededor">
  <meta name="theme-color" content="#071018">
  <meta name="mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
  <meta name="apple-mobile-web-app-title" content="Canarias SVQ">
  <title><?= e($title) ?> · Sevilla</title>
  <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'%3E%3Crect width='64' height='64' rx='14' fill='%23071018'/%3E%3Cpath d='M12 38h40M18 30h28' stroke='%23ffb547' stroke-width='6' stroke-linecap='round'/%3E%3Ccircle cx='22' cy='46' r='5' fill='%23ff5f6d'/%3E%3Ccircle cx='42' cy='46' r='5' fill='%23ff5f6d'/%3E%3C/svg%3E">
  <link href="assets/css/canarias.css?v=<?= e($cssVersion) ?>" rel="stylesheet">
</head>
<body>
  <div class="sheet-shell" id="sheetShell"
       data-night="<?= $night ? '1' : '0' ?>"
       data-week="<?= $week ? '1' : '0' ?>"
       data-origin="<?= e($origin) ?>"
       data-date="<?= e($date) ?>"
       data-night-from="<?= e((string)$nightFrom) ?>"
       data-today="<?= e($today) ?>">
    <header class="sheet-top">
      <div class="sheet-brand">
        <span class="sheet-mark">SVQ</span>
        <div>
          <strong id="sheetTitle"><?= e($title) ?></strong>
          <small id="sheetMeta">Cargando ocupación…</small>
        </div>
      </div>
      <div class="sheet-clock" aria-live="off">
        <time id="sheetClock">--:--:--</time>
        <small id="sheetDay"><?= e($today) ?></small>
      </div>
      <div class="sheet-actions">
        <button type="button" class="sheet-btn" id="sheetPrint">Imprimir</button>
        <a class="sheet-btn sheet-btn-ghost" href="index.php<?= $date !== $today ? '?date=' . rawurlencode($date) : '' ?>">Matriz</a>
      </div>
    </header>
    <p class="sheet-error" id="sheetError" role="alert" hidden></p>
    <main class="sheet-body" id="sheetBody">
      <p class="sheet-loading">Cargando Canarias…</p>
    </main>
  </div>
  <script src="assets/js/canarias.js?v=<?= e($jsVersion) ?>"></script>
</body>
</html>
