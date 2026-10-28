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
  <link rel="manifest" href="manifest.webmanifest">
  <link rel="apple-touch-icon" href="assets/icon-180.png" sizes="180x180">
  <title><?= e($title) ?> · Sevilla</title>
  <link rel="icon" type="image/png" sizes="192x192" href="assets/icon-192.png">
  <link rel="icon" type="image/svg+xml" href="assets/icon.svg">
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
        <a class="sheet-icon-btn is-live" href="cintas.php" title="Cintas en vivo" aria-label="Cintas en vivo">
          <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="1.8"/><circle cx="12" cy="12" r="5" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M12 12l6.2-3.4" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><circle cx="12" cy="12" r="1.7" fill="currentColor"/></svg>
        </a>
        <button type="button" class="sheet-icon-btn" id="sheetPrint" title="Imprimir" aria-label="Imprimir">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 8V3.8h10V8" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="M5 8h14a2 2 0 0 1 2 2v6h-3.2v-2.2H6.2V16H3v-6a2 2 0 0 1 2-2z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><rect x="6.2" y="13.8" width="11.6" height="6.4" rx="1.2" fill="none" stroke="currentColor" stroke-width="1.8"/></svg>
        </button>
        <a class="sheet-icon-btn" href="index.php<?= $date !== $today ? '?date=' . rawurlencode($date) : '' ?>" title="Matriz" aria-label="Matriz">
          <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="3" width="7.2" height="7.2" rx="1.2" fill="none" stroke="currentColor" stroke-width="1.8"/><rect x="13.8" y="3" width="7.2" height="7.2" rx="1.2" fill="none" stroke="currentColor" stroke-width="1.8"/><rect x="3" y="13.8" width="7.2" height="7.2" rx="1.2" fill="none" stroke="currentColor" stroke-width="1.8"/><rect x="13.8" y="13.8" width="7.2" height="7.2" rx="1.2" fill="none" stroke="currentColor" stroke-width="1.8"/></svg>
        </a>
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
