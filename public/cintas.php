<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';

use SevillaMatrix\Env;
use SevillaMatrix\OperationalDay;

$pollSeconds = max(5, (int)Env::get('POLL_SECONDS', '15'));
$today = OperationalDay::current();
$cssVersion = (string)(@filemtime(__DIR__ . '/assets/css/cintas.css') ?: '1');
$jsVersion = (string)(@filemtime(__DIR__ . '/assets/js/cintas.js') ?: '1');
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="description" content="Cintas de equipaje en vivo del aeropuerto de Sevilla">
  <meta name="theme-color" content="#050b12">
  <meta name="mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
  <meta name="apple-mobile-web-app-title" content="Cintas SVQ">
  <title>Cintas en vivo · Sevilla</title>
  <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'%3E%3Crect width='64' height='64' rx='14' fill='%23050b12'/%3E%3Cpath d='M12 38h40M18 30h28' stroke='%232fd38a' stroke-width='6' stroke-linecap='round'/%3E%3Ccircle cx='22' cy='46' r='5' fill='%23ff5f6d'/%3E%3Ccircle cx='42' cy='46' r='5' fill='%23ff5f6d'/%3E%3C/svg%3E">
  <link href="assets/css/cintas.css?v=<?= e($cssVersion) ?>" rel="stylesheet">
</head>
<body>
  <div class="live-shell" id="liveShell" data-poll-seconds="<?= $pollSeconds ?>" data-operational-today="<?= e($today) ?>">
    <header class="live-top">
      <div class="live-brand">
        <span class="live-mark">SVQ</span>
        <div>
          <strong>Cintas en vivo</strong>
          <small>Sala A · 1–6 verdes · Sala B · 7–8 rojas</small>
        </div>
      </div>
      <div class="live-clock" aria-live="off">
        <time id="liveClock">--:--:--</time>
        <small>Madrid</small>
      </div>
      <div class="live-day">
        <small id="liveDayRelative">Hoy</small>
        <strong id="liveDayLabel"><?= e($today) ?></strong>
      </div>
      <div class="live-actions">
        <span id="liveStatus" class="live-status is-loading">Cargando</span>
        <button type="button" class="live-btn" id="liveFullscreen" aria-pressed="false">Pantalla</button>
        <a class="live-btn live-btn-ghost" href="index.php">Matriz</a>
      </div>
    </header>

    <p class="live-banner" id="liveError" role="alert" hidden></p>

    <section class="live-grid" id="liveGrid" aria-label="Ocho cintas de equipaje"></section>

    <footer class="live-scrub">
      <div class="live-scrub-head">
        <div>
          <small>Hora vista</small>
          <strong id="liveViewTime">Ahora</strong>
        </div>
        <div class="live-scrub-meta">
          <span id="liveModelNote">Ocupación: llegada prevista + 30 min · Aena prevalece</span>
          <span id="liveUpdated">Esperando datos…</span>
        </div>
        <button type="button" class="live-play" id="livePlay" aria-pressed="false" title="Avanzar un minuto cada segundo">▶</button>
      </div>
      <label class="live-slider-wrap" for="liveSlider">
        <span class="visually-hidden">Adelantar el gráfico hasta 90 minutos</span>
        <input type="range" id="liveSlider" min="0" max="90" step="1" value="0" list="liveSliderTicks">
        <datalist id="liveSliderTicks">
          <option value="0" label="Ahora"></option>
          <option value="15" label="+15"></option>
          <option value="30" label="+30"></option>
          <option value="45" label="+45"></option>
          <option value="60" label="+60"></option>
          <option value="90" label="+90"></option>
        </datalist>
        <div class="live-ticks" aria-hidden="true">
          <span>Ahora</span><span>+15</span><span>+30</span><span>+45</span><span>+60</span><span>+90</span>
        </div>
      </label>
    </footer>
  </div>
  <script src="assets/js/cintas.js?v=<?= e($jsVersion) ?>"></script>
</body>
</html>
