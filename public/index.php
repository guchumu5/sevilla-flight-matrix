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
      <div class="col-12 col-md-3 text-md-end">
        <button class="btn btn-success w-100 w-md-auto" id="refreshButton" type="button">Actualizar ahora</button>
      </div>
    </div>
  </section>

  <section class="row g-3 mb-3" aria-label="Resumen operativo">
    <div class="col-6 col-xl-3"><article class="metric-card"><span>Vuelos</span><strong id="metricFlights">—</strong><small>en el tablero</small></article></div>
    <div class="col-6 col-xl-3"><article class="metric-card metric-orange"><span>En revisión</span><strong id="metricOrange">—</strong><small>presión, desviación o propuesta</small></article></div>
    <div class="col-6 col-xl-3"><article class="metric-card metric-red"><span>Cintas 7/8</span><strong id="metricRed">—</strong><small>ubicación operativa</small></article></div>
    <div class="col-6 col-xl-3"><article class="metric-card metric-blue"><span>Afluencia máxima</span><strong id="metricHall">—</strong><small>plazas teóricas ±30 min</small></article></div>
  </section>

  <section class="panel overflow-hidden">
    <header class="panel-header d-flex flex-wrap align-items-center justify-content-between gap-2">
      <div>
        <h1 class="h5 mb-1">Llegadas físicas</h1>
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
<script src="assets/js/app.js?v=<?= e($jsVersion) ?>"></script>
</body>
</html>
