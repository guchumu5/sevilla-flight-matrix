<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';

use SevillaMatrix\Auth;

if (!Auth::check()) {
    header('Location: login.php');
    exit;
}
?>
<!doctype html>
<html lang="es" data-bs-theme="dark">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Cerebro de eventos · Matriz Sevilla</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="assets/css/app.css" rel="stylesheet">
</head>
<body>
<nav class="navbar app-navbar border-bottom"><div class="container-fluid px-lg-4">
  <a class="navbar-brand" href="admin.php">← Administración</a>
  <div class="d-flex gap-2"><a class="btn btn-sm btn-outline-light" href="index.php">Tablero</a><a class="btn btn-sm btn-outline-light" href="logout.php">Salir</a></div>
</div></nav>

<main class="container-fluid px-3 px-lg-4 py-4">
  <section class="panel p-3 p-md-4 mb-4">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
      <div><span class="badge text-bg-info mb-2">Conciliación v1</span><h1 class="h3 mb-2">Cerebro de eventos</h1>
      <p class="text-secondary mb-0">Cada captura se compara con la anterior. Los cambios, ausencias, reapariciones y motivos publicados quedan registrados sin borrar el histórico.</p></div>
      <button class="btn btn-outline-light" id="refreshBrain" type="button">Actualizar</button>
    </div>
  </section>

  <div id="brainAlert" class="alert d-none"></div>
  <section class="row g-3 mb-4" aria-label="Resumen del cerebro">
    <div class="col-6 col-lg-3"><article class="metric-card"><span>Último éxito</span><strong class="fs-6" id="lastSuccess">—</strong><small>sincronización terminada</small></article></div>
    <div class="col-6 col-lg-3"><article class="metric-card"><span>Eventos hoy</span><strong id="eventsToday">—</strong><small>cambios inmutables</small></article></div>
    <div class="col-6 col-lg-3"><article class="metric-card metric-orange"><span>Cintas hoy</span><strong id="beltEvents">—</strong><small>asignación, cambio o retirada</small></article></div>
    <div class="col-6 col-lg-3"><article class="metric-card"><span>Ausencias hoy</span><strong id="visibilityEvents">—</strong><small>sin borrar vuelos</small></article></div>
  </section>

  <section class="panel overflow-hidden mb-4">
    <header class="panel-header"><h2 class="h5 mb-1">Intentos de los recolectores</h2><p class="text-secondary mb-0">Aparecen aunque AirLabs u OpenSky respondan sin vuelos. Así se distingue una consulta vacía de un cron que nunca llegó a PHP.</p></header>
    <div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Estado</th><th>Fuente</th><th>Registros</th><th>Detalle</th><th>Hora</th></tr></thead><tbody id="fetchRunsBody"><tr><td colspan="5" class="text-center py-5 text-secondary">Cargando…</td></tr></tbody></table></div>
  </section>

  <section class="panel overflow-hidden mb-4">
    <header class="panel-header"><h2 class="h5 mb-1">Ejecuciones recientes</h2><p class="text-secondary mb-0">Permite saber qué fuente se consultó, cuándo y qué produjo.</p></header>
    <div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Estado</th><th>Fuente / modo</th><th>Ventana</th><th>Resultado</th><th>Hora</th></tr></thead><tbody id="runsBody"><tr><td colspan="5" class="text-center py-5 text-secondary">Cargando…</td></tr></tbody></table></div>
  </section>

  <section class="panel overflow-hidden">
    <header class="panel-header"><h2 class="h5 mb-1">Histórico de eventos</h2><p class="text-secondary mb-0">El motivo distingue lo publicado por la fuente de lo que no puede verificarse.</p></header>
    <div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Vuelo</th><th>Evento</th><th>Antes → después</th><th>Motivo / confianza</th><th>Detectado</th></tr></thead><tbody id="eventsBody"><tr><td colspan="5" class="text-center py-5 text-secondary">Cargando…</td></tr></tbody></table></div>
  </section>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
(() => {
  'use strict';
  const esc = value => String(value ?? '').replace(/[&<>'"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));
  const empty = value => value === null || value === '' ? '∅' : esc(value);
  const statusBadge = status => `<span class="badge ${status === 'success' ? 'text-bg-success' : status === 'failed' ? 'text-bg-danger' : status === 'partial' ? 'text-bg-warning' : 'text-bg-primary'}">${esc(status)}</span>`;
  const confidenceBadge = value => `<span class="badge ${value === 'confirmado' ? 'text-bg-success' : value === 'probable' ? 'text-bg-warning' : 'text-bg-secondary'}">${esc(value)}</span>`;

  async function load() {
    try {
      const response = await fetch('api/sync-runs.php?runs=20&events=100', {cache:'no-store', headers:{Accept:'application/json'}});
      const data = await response.json();
      if (!response.ok) throw new Error(data.error || 'No se pudo consultar el cerebro.');
      document.querySelector('#lastSuccess').textContent = data.summary.last_success_at || 'Todavía no';
      document.querySelector('#eventsToday').textContent = data.summary.events_today;
      document.querySelector('#beltEvents').textContent = data.summary.belt_events_today;
      document.querySelector('#visibilityEvents').textContent = data.summary.visibility_events_today;
      const fetchRuns = data.fetch_runs || [];
      document.querySelector('#fetchRunsBody').innerHTML = fetchRuns.length ? fetchRuns.map(run => `<tr>
        <td><span class="badge ${Number(run.ok) === 1 ? 'text-bg-success' : 'text-bg-danger'}">${Number(run.ok) === 1 ? 'Ejecutado' : 'Error'}</span></td>
        <td><strong>${esc(run.provider)}</strong><span class="meta d-block">intento #${esc(run.id)}</span></td>
        <td><strong>${Number(run.records_count).toLocaleString('es-ES')}</strong></td>
        <td>${run.error_message ? `<span class="text-danger">${esc(run.error_message)}</span>` : '<span class="text-secondary">La fuente respondió sin error técnico.</span>'}</td>
        <td>${empty(run.finished_at || run.started_at)}</td></tr>`).join('') : '<tr><td colspan="5" class="text-center py-5 text-secondary">Todavía no hay intentos registrados. Aplica la actualización de recolectores y ejecuta un proceso.</td></tr>';
      document.querySelector('#runsBody').innerHTML = data.runs.length ? data.runs.map(run => `<tr>
        <td>${statusBadge(run.status)}</td><td><strong>${esc(run.provider)}</strong><span class="meta d-block">${esc(run.mode)} · ejecución #${esc(run.id)}</span></td>
        <td>${empty(run.window_from)}<span class="meta d-block">hasta ${empty(run.window_to)}</span></td>
        <td><strong>${Number(run.records_received).toLocaleString('es-ES')} recibidos</strong><span class="meta d-block">+${esc(run.flights_created)} vuelos · ${esc(run.flights_updated)} actualizados · ${esc(run.events_created)} eventos · ${esc(run.flights_withdrawn)} retirados</span>${run.error_message ? `<span class="text-danger d-block">${esc(run.error_message)}</span>` : ''}</td>
        <td>${empty(run.finished_at || run.started_at)}</td></tr>`).join('') : '<tr><td colspan="5" class="text-center py-5 text-secondary">Aún no hay ejecuciones.</td></tr>';
      document.querySelector('#eventsBody').innerHTML = data.events.length ? data.events.map(event => `<tr>
        <td><strong>${esc(event.physical_flight)}</strong><span class="meta d-block">${esc(event.origin_name)} · ${esc(event.flight_date)}</span></td>
        <td><strong>${esc(event.event_type)}</strong><span class="meta d-block">${esc(event.source)} · ${esc(event.field_name || 'vuelo')}</span></td>
        <td><span class="text-secondary">${empty(event.before_value)}</span> → <strong>${empty(event.after_value)}</strong></td>
        <td>${esc(event.reason_detail)}<span class="meta d-block">${esc(event.reason_code)} · ${confidenceBadge(event.confidence)}</span></td>
        <td>${esc(event.detected_at)}</td></tr>`).join('') : '<tr><td colspan="5" class="text-center py-5 text-secondary">Aún no hay eventos.</td></tr>';
    } catch (error) {
      const alert = document.querySelector('#brainAlert'); alert.className='alert alert-danger'; alert.textContent=error.message;
    }
  }
  document.querySelector('#refreshBrain').addEventListener('click', load);
  load();
})();
</script>
</body>
</html>
