<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';

use SevillaMatrix\Env;

$pollSeconds = max(5, (int)Env::get('POLL_SECONDS', '15'));
$today = date('Y-m-d');
$cssVersion = (string)(@filemtime(__DIR__ . '/assets/css/app.css') ?: '1');
$jsVersion = (string)(@filemtime(__DIR__ . '/assets/js/app.js') ?: '1');
?>
<!doctype html>
<html lang="es" data-bs-theme="dark">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="description" content="Matriz operativa de llegadas y cintas del aeropuerto de Sevilla">
  <title>Matriz de Cintas · Sevilla</title>
  <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'%3E%3Crect width='64' height='64' rx='14' fill='%230b1728'/%3E%3Cpath d='M12 38h40M18 30h28' stroke='%232fd38a' stroke-width='6' stroke-linecap='round'/%3E%3Ccircle cx='22' cy='46' r='5' fill='%23ffb547'/%3E%3Ccircle cx='42' cy='46' r='5' fill='%23ffb547'/%3E%3C/svg%3E">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.css" rel="stylesheet">
  <link href="assets/css/app.css?v=<?= e($cssVersion) ?>" rel="stylesheet">
</head>
<body>
<nav class="navbar navbar-expand-lg sticky-top app-navbar border-bottom">
  <div class="container-fluid px-lg-4">
    <a class="navbar-brand d-flex align-items-center gap-2" href="#">
      <span class="brand-mark">SVQ</span>
      <span><strong>Matriz de Cintas</strong><small class="d-block text-secondary">Llegadas · Sevilla</small></span>
    </a>
    <div class="d-flex align-items-center gap-2 ms-auto">
      <span id="connectionBadge" class="badge text-bg-secondary">Conectando</span>
      <a class="btn btn-sm btn-outline-light" href="admin.php">Aena / Admin</a>
    </div>
  </div>
</nav>

<main class="container-fluid px-lg-4 py-3" data-poll-seconds="<?= $pollSeconds ?>">
  <section class="toolbar panel mb-3" aria-label="Filtros del tablero">
    <div class="row g-2 align-items-end">
      <div class="col-6 col-md-2">
        <label class="form-label" for="flightDate">Fecha</label>
        <input class="form-control" type="date" id="flightDate" value="<?= e($today) ?>">
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label" for="hallFilter">Sala</label>
        <select class="form-select" id="hallFilter">
          <option value="">Todas</option><option value="A">A</option><option value="B">B</option>
        </select>
      </div>
      <div class="col-7 col-md-3">
        <label class="form-label" for="searchInput">Buscar</label>
        <input class="form-control" id="searchInput" placeholder="Origen o vuelo" autocomplete="off">
      </div>
      <div class="col-6 col-md-1">
        <div class="form-check form-switch filter-switch">
          <input class="form-check-input" type="checkbox" role="switch" id="canaryOnly">
          <label class="form-check-label" for="canaryOnly">Canarias</label>
        </div>
      </div>
      <div class="col-6 col-md-1">
        <div class="form-check form-switch filter-switch">
          <input class="form-check-input" type="checkbox" role="switch" id="secondaryOnly">
          <label class="form-check-label" for="secondaryOnly">Cintas API</label>
        </div>
      </div>
      <div class="col-12 col-md-3 text-md-end d-flex gap-2 justify-content-md-end">
        <button class="btn btn-outline-light flex-fill flex-md-grow-0" id="nowButton" type="button">Ir a ahora</button>
        <button class="btn btn-success flex-fill flex-md-grow-0" id="refreshButton" type="button">Actualizar</button>
      </div>
    </div>
  </section>

  <section class="row g-3 mb-3" aria-label="Resumen operativo">
    <div class="col-6 col-xl-3"><article class="metric-card"><span>Vuelos</span><strong id="metricFlights">—</strong><small>en el tablero</small></article></div>
    <div class="col-6 col-xl-3"><article class="metric-card metric-orange"><span>En revisión</span><strong id="metricOrange">—</strong><small>presión, desviación o propuesta</small></article></div>
    <div class="col-6 col-xl-3"><article class="metric-card metric-red"><span>Cintas 7/8</span><strong id="metricRed">—</strong><small>ubicación operativa</small></article></div>
    <div class="col-6 col-xl-3"><article class="metric-card metric-blue"><span>Afluencia máxima</span><strong id="metricHall">—</strong><small>plazas teóricas ±30 min</small></article></div>
  </section>

  <section class="panel canary-watch-panel mb-3" aria-labelledby="canaryWatchTitle">
    <header class="panel-header d-flex align-items-center justify-content-between gap-2">
      <div>
        <h2 class="h6 mb-1" id="canaryWatchTitle">Canarias siempre visible</h2>
        <p class="mb-0 text-secondary small">Todos los vuelos canarios del día, aunque filtres la tabla principal.</p>
      </div>
      <span class="canary-watch-mark" aria-hidden="true">🌴</span>
    </header>
    <div class="canary-strip" id="canaryWatch" aria-live="polite"></div>
  </section>

  <section class="panel upcoming-panel mb-3" aria-labelledby="upcomingTitle">
    <header class="panel-header d-flex align-items-center justify-content-between gap-2">
      <div><h2 class="h6 mb-1" id="upcomingTitle">Próximas 10 llegadas</h2><p class="mb-0 text-secondary small">Vuelo, procedencia, hora efectiva y cinta oficial.</p></div>
      <span class="badge text-bg-primary">línea operativa</span>
    </header>
    <div class="upcoming-strip" id="upcomingStrip" aria-live="polite"></div>
  </section>

  <section class="panel mb-3 overflow-hidden" aria-label="Aeropuerto de Sevilla en movimiento">
    <header class="panel-header d-flex align-items-start justify-content-between gap-2">
      <div>
        <h2 class="h5 mb-1">Aeropuerto de Sevilla en movimiento</h2>
        <p class="mb-0 text-secondary small">Posición GPS real cuando existe ADS‑B; sin señal, el vuelo queda en cola prevista. La ruta hacia la cinta representa el equipaje, no el rodaje del avión.</p>
      </div>
      <time class="scene-clock" id="sceneClock">--:--</time>
    </header>
    <div class="airport-scene airport-scene-large" id="airportScene" aria-label="Infografía dinámica del flujo de llegadas">
          <svg viewBox="0 0 900 360" preserveAspectRatio="xMidYMid slice" aria-hidden="true">
            <defs>
              <linearGradient id="sceneSky" x1="0" y1="0" x2="0" y2="1"><stop stop-color="#102f4a"/><stop offset="1" stop-color="#081522"/></linearGradient>
              <pattern id="runwayMarks" width="70" height="36" patternUnits="userSpaceOnUse"><rect x="27" y="15" width="28" height="6" rx="2" fill="#eaf3fa" opacity=".88"/></pattern>
            </defs>
            <rect width="900" height="360" fill="url(#sceneSky)"/>
            <path class="scene-coast" d="M0 305 C130 250 225 332 355 282 S615 270 900 238 V360 H0Z"/>
            <path class="scene-approach-line" d="M18 76 C145 78 205 120 295 178"/>
            <path class="scene-approach-line scene-approach-right" d="M882 76 C755 78 690 120 602 178"/>
            <path class="scene-taxiway" d="M560 181 C650 183 650 250 722 257"/>
            <rect class="scene-runway" x="280" y="158" width="330" height="42" rx="5"/>
            <rect x="290" y="161" width="310" height="36" fill="url(#runwayMarks)"/>
            <g class="runway-lights"><circle cx="280" cy="154" r="3"/><circle cx="330" cy="154" r="3"/><circle cx="380" cy="154" r="3"/><circle cx="430" cy="154" r="3"/><circle cx="480" cy="154" r="3"/><circle cx="530" cy="154" r="3"/><circle cx="580" cy="154" r="3"/><circle cx="610" cy="154" r="3"/></g>
            <g class="scene-terminal"><path d="M675 218h198v73H675z"/><path d="M700 198h38v26h-38zM755 198h38v26h-38zM810 198h38v26h-38z"/><text x="774" y="259">TERMINAL · PLANTA 0</text></g>
            <text class="scene-label" x="24" y="42">COLA OESTE</text><text class="scene-label" x="196" y="112">APROXIMACIÓN</text><text class="scene-label" x="416" y="146">PISTA</text><text class="scene-label" x="610" y="213">TIERRA</text><text class="scene-label" x="785" y="42">COLA ESTE</text>
          </svg>
          <svg class="scene-trails" id="sceneTrails" viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true"></svg>
          <div class="scene-aircraft-layer" id="sceneAircraft"></div>
          <div class="scene-source"><span></span> Aena + ADS‑B <b id="sceneMovementCount">0</b></div>
          <section class="scene-belts" aria-label="Cintas de equipajes de derecha a izquierda">
            <header><span>← SALA B · CINTA 8</span><strong>RECOGIDA DE EQUIPAJES</strong><span>CINTA 1 · SALA A →</span></header>
            <div class="scene-belts-grid" id="sceneBelts"></div>
          </section>
    </div>
    <section class="belt-changes-section d-none" id="beltChangesSection" aria-labelledby="beltChangesTitle">
      <header><div><strong id="beltChangesTitle">Cambios de cinta</strong><small>Primera asignación, cambio oficial y contexto observado</small></div><span class="badge text-bg-warning" id="beltChangesCount">0</span></header>
      <div class="belt-changes-strip" id="beltChangesStrip"></div>
    </section>
    <details class="flow-details">
      <summary>Ver desglose por fase</summary>
      <div class="airport-flow airport-flow-wide" id="airportFlow" aria-live="polite"></div>
    </details>
  </section>

  <section class="panel mb-3 overflow-hidden radar-collapsed-panel">
    <button class="radar-toggle" type="button" data-bs-toggle="collapse" data-bs-target="#radarCollapse" aria-expanded="false" aria-controls="radarCollapse">
      <span><strong>Radar ADS‑B</strong><small id="radarToggleLabel">plegado · pulsa para abrir</small></span><b aria-hidden="true">⌄</b>
    </button>
    <div class="collapse" id="radarCollapse">
      <header class="panel-header d-flex align-items-center justify-content-between gap-2">
        <p class="mb-0 text-secondary small" id="mapStatus">Esperando posiciones OpenSky…</p>
        <button class="btn btn-sm btn-outline-light" id="fitMapButton" type="button">Encuadrar</button>
      </header>
      <div id="flightMap" role="application" aria-label="Mapa de aeronaves que llegan a Sevilla"></div>
    </div>
  </section>

  <section class="panel overflow-hidden" id="arrivalsPanel">
    <header class="panel-header d-flex flex-wrap align-items-center justify-content-between gap-2">
      <div>
        <h1 class="h5 mb-1">Llegadas físicas · ventana 5 + 5 + 5</h1>
        <p class="mb-0 text-secondary" id="lastUpdated">Esperando datos…</p>
      </div>
      <div class="legend d-flex flex-wrap gap-2" aria-label="Leyenda">
        <span>🟢 estable</span><span>🟠 revisión</span><span>🔵 en vuelo</span><span>🔴 7/8</span><span>🟡 propuesta secundaria</span>
      </div>
    </header>

    <div id="errorAlert" class="alert alert-danger m-3 d-none" role="alert"></div>
    <div id="loadingState" class="loading-state"><div class="spinner-border text-success" role="status"></div><span>Actualizando tablero…</span></div>
    <div id="emptyState" class="empty-state d-none"><strong>No hay vuelos para estos filtros.</strong><span>Prueba otra fecha o elimina algún filtro.</span></div>

    <div class="table-responsive d-none" id="tableWrap">
      <table class="table table-hover align-middle mb-0 flights-table">
        <thead><tr>
          <th>Estado</th><th>Hora</th><th>Cinta</th><th>Origen</th><th>Vuelo físico</th>
          <th>ETA/Real</th><th>Intervalo</th><th>Afluencia sala</th><th>Fuente</th>
        </tr></thead>
        <tbody id="flightsBody"></tbody>
      </table>
    </div>
  </section>
</main>

<div class="offcanvas offcanvas-end detail-drawer" tabindex="-1" id="flightDetail" aria-labelledby="flightDetailLabel">
  <div class="offcanvas-header border-bottom">
    <div><small class="text-secondary">Histórico del vuelo</small><h2 class="offcanvas-title h5" id="flightDetailLabel">Detalle</h2></div>
    <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Cerrar"></button>
  </div>
  <div class="offcanvas-body" id="detailBody"><div class="loading-state"><div class="spinner-border spinner-border-sm"></div></div></div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="assets/js/app.js?v=<?= e($jsVersion) ?>"></script>
</body>
</html>
