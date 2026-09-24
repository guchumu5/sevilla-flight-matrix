<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';

use SevillaMatrix\Auth;

if (!Auth::check()) { header('Location: login.php'); exit; }
?>
<!doctype html>
<html lang="es" data-bs-theme="dark">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Procesos web · Matriz Sevilla</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="assets/css/app.css" rel="stylesheet">
</head>
<body>
<nav class="navbar app-navbar border-bottom"><div class="container-fluid px-lg-4">
  <a class="navbar-brand" href="admin.php">← Administración</a>
  <div class="d-flex gap-2"><a class="btn btn-sm btn-outline-info" href="sync-runs.php">Cerebro</a><a class="btn btn-sm btn-outline-light" href="logout.php">Salir</a></div>
</div></nav>

<main class="container py-4" data-csrf="<?= e(Auth::csrf()) ?>">
  <section class="panel p-3 p-md-4 mb-4">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
      <div><span class="badge text-bg-success mb-2">Sin terminal</span><h1 class="h3 mb-2">Centro de procesos</h1><p class="text-secondary mb-0">Comprueba la configuración y actualiza las fuentes desde la sesión de administrador.</p></div>
      <button class="btn btn-outline-light" id="refreshStatus" type="button">Revisar configuración</button>
    </div>
  </section>

  <div id="operationAlert" class="alert d-none" role="alert"></div>

  <section class="row g-3 mb-4">
    <div class="col-md-6 col-xl-3"><article class="metric-card"><span>Aena</span><strong id="aenaState">—</strong><small>estado, sala y cinta oficiales</small></article></div>
    <div class="col-md-6 col-xl-3"><article class="metric-card"><span>AirLabs</span><strong id="airlabsState">—</strong><small>horas, estados y datos secundarios</small></article></div>
    <div class="col-md-6 col-xl-3"><article class="metric-card"><span>OpenSky</span><strong id="openskyState">—</strong><small>posición ADS-B con ICAO24 conocido</small></article></div>
    <div class="col-md-6 col-xl-3"><article class="metric-card"><span>Meteorología</span><strong id="weatherState">—</strong><small>METAR LEZL sin clave API</small></article></div>
  </section>

  <section class="panel p-3 p-md-4 mb-4">
    <div class="d-flex flex-wrap align-items-end justify-content-between gap-3 mb-3">
      <div><h2 class="h5 mb-1">Ejecutar actualizaciones</h2><p class="text-secondary mb-0">Los límites evitan agotar cuotas o bloquear el hosting.</p></div>
      <div><label class="form-label small" for="operationLimit">Máximo de vuelos</label><input class="form-control" id="operationLimit" type="number" min="1" max="20" value="10" style="width:9rem"></div>
    </div>
    <div class="row g-3">
      <div class="col-sm-6 col-xl-3"><button class="btn btn-primary w-100 operation-button" data-action="airlabs">Actualizar AirLabs</button></div>
      <div class="col-sm-6 col-xl-3"><button class="btn btn-info w-100 operation-button" data-action="opensky">Actualizar OpenSky</button></div>
      <div class="col-sm-6 col-xl-3"><button class="btn btn-outline-light w-100 operation-button" data-action="weather">Actualizar tiempo</button></div>
      <div class="col-sm-6 col-xl-3"><button class="btn btn-success w-100 operation-button" data-action="all">Actualizar todo</button></div>
    </div>
    <p class="small text-secondary mt-3 mb-0">Aena se recoge mediante el barrido automático de Infovuelos y prevalece en estado, sala y cinta. AirLabs entra como fuente provisional; OpenSky solo añade telemetría y AviationWeather el METAR.</p>
  </section>

  <div class="row g-4">
    <div class="col-lg-7"><section class="panel overflow-hidden h-100"><header class="panel-header"><h2 class="h5 mb-1">Últimas ejecuciones del cerebro</h2><p class="text-secondary mb-0">Observaciones y eventos creados por cada captura.</p></header><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Hora</th><th>Fuente</th><th>Estado</th><th>Resultado</th></tr></thead><tbody id="runsBody"><tr><td colspan="4" class="text-secondary">Cargando…</td></tr></tbody></table></div></section></div>
    <div class="col-lg-5"><section class="panel p-3 p-md-4 h-100"><h2 class="h5">Diagnóstico</h2><div id="diagnostics" class="vstack gap-2 text-secondary">Cargando…</div><hr><p class="small text-secondary mb-0">Ningún botón permite ejecutar comandos, indicar rutas ni enviar SQL. Las acciones están fijadas en el servidor, requieren sesión y token CSRF, y bloquean dobles ejecuciones.</p></section></div>
  </div>

  <section class="panel overflow-hidden mt-4">
    <header class="panel-header"><h2 class="h5 mb-1">Ejecuciones de los recolectores</h2><p class="text-secondary mb-0">Confirma si el cron llegó a PHP aunque la API no devolviera vuelos.</p></header>
    <div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Inicio</th><th>Proceso</th><th>Estado</th><th>Registros</th><th>Detalle</th></tr></thead><tbody id="fetchRunsBody"><tr><td colspan="5" class="text-secondary">Cargando…</td></tr></tbody></table></div>
  </section>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
(() => {
  'use strict';
  const csrf = document.querySelector('main').dataset.csrf;
  const alertBox = document.querySelector('#operationAlert');
  const esc = value => String(value ?? '').replace(/[&<>'"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));
  const state = value => value ? '<span class="text-success">LISTO</span>' : '<span class="text-danger">PENDIENTE</span>';

  function showAlert(message, ok = true, details = null) {
    alertBox.className = `alert ${ok ? 'alert-success' : 'alert-danger'}`;
    alertBox.innerHTML = `<strong>${esc(message)}</strong>${details ? `<pre class="small mt-2 mb-0 text-wrap">${esc(JSON.stringify(details, null, 2))}</pre>` : ''}`;
    alertBox.scrollIntoView({behavior:'smooth', block:'center'});
  }

  async function loadStatus() {
    try {
      const response = await fetch('api/operations.php', {headers:{Accept:'application/json'}, cache:'no-store'});
      const data = await response.json();
      if (!response.ok) throw new Error(data.error || 'No se pudo leer el diagnóstico.');
      document.querySelector('#aenaState').innerHTML = state(data.providers.aena);
      document.querySelector('#airlabsState').innerHTML = state(data.providers.airlabs);
      document.querySelector('#openskyState').innerHTML = state(data.providers.opensky);
      document.querySelector('#weatherState').innerHTML = state(data.providers.aviationweather);
      const items = [
        ['PHP ' + data.php_version, true],
        ['Extensión PDO MySQL', data.extensions.pdo_mysql],
        ['Extensión cURL', data.extensions.curl],
        ['Caché escribible', data.storage.cache_writable],
        ['Logs escribibles', data.storage.logs_writable],
        ...data.tables.map(table => ['Tabla ' + table.name, table.ready])
      ];
      document.querySelector('#diagnostics').innerHTML = items.map(item => `<div class="d-flex justify-content-between gap-3"><span>${esc(item[0])}</span><span class="badge ${item[1] ? 'text-bg-success' : 'text-bg-danger'}">${item[1] ? 'OK' : 'FALTA'}</span></div>`).join('');
      document.querySelector('#runsBody').innerHTML = data.latest_runs.length ? data.latest_runs.map(run => `<tr><td>${esc(run.started_at)}</td><td>${esc(run.provider)}</td><td><span class="badge ${run.status === 'success' ? 'text-bg-success' : run.status === 'failed' ? 'text-bg-danger' : 'text-bg-warning'}">${esc(run.status)}</span></td><td>${Number(run.observations_created)} obs. · ${Number(run.events_created)} eventos${run.error_message ? `<span class="d-block small text-danger">${esc(run.error_message)}</span>` : ''}</td></tr>`).join('') : '<tr><td colspan="4" class="text-secondary">Todavía no hay ejecuciones.</td></tr>';
      document.querySelector('#fetchRunsBody').innerHTML = data.latest_fetch_runs.length ? data.latest_fetch_runs.map(run => `<tr><td>${esc(run.started_at)}</td><td>${esc(run.provider)}</td><td><span class="badge ${Number(run.ok) === 1 ? 'text-bg-success' : 'text-bg-danger'}">${Number(run.ok) === 1 ? 'Correcto' : 'Error'}</span></td><td>${Number(run.records_count)}</td><td>${run.error_message ? `<span class="text-danger">${esc(run.error_message)}</span>` : '<span class="text-secondary">Sin error</span>'}</td></tr>`).join('') : '<tr><td colspan="5" class="text-secondary">Ningún recolector ha alcanzado todavía la aplicación.</td></tr>';
    } catch (error) { showAlert(error.message, false); }
  }

  async function run(button) {
    const action = button.dataset.action;
    const form = new FormData();
    form.set('csrf', csrf); form.set('action', action); form.set('limit', document.querySelector('#operationLimit').value);
    const original = button.textContent;
    document.querySelectorAll('.operation-button').forEach(item => item.disabled = true);
    button.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Ejecutando';
    try {
      const response = await fetch('api/operations.php', {method:'POST', body:form, headers:{Accept:'application/json'}});
      const data = await response.json();
      if (!response.ok || data.ok === false) throw new Error(data.error || data.message || 'El proceso no pudo completarse.');
      showAlert(data.message || 'Proceso completado.', true, data);
      await loadStatus();
    } catch (error) { showAlert(error.message, false); }
    finally { document.querySelectorAll('.operation-button').forEach(item => item.disabled = false); button.textContent = original; }
  }

  document.querySelectorAll('.operation-button').forEach(button => button.addEventListener('click', () => run(button)));
  document.querySelector('#refreshStatus').addEventListener('click', loadStatus);
  loadStatus();
})();
</script>
</body>
</html>
