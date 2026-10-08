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
    <div class="col-md-6 col-xl-3"><article class="metric-card"><span>Aena</span><strong id="aenaState">—</strong><small id="aenaDetail">estado, sala y cinta oficiales</small></article></div>
    <div class="col-md-6 col-xl-3"><article class="metric-card"><span>AirLabs</span><strong id="airlabsState">—</strong><small id="airlabsDetail">consulta agrupada de llegadas</small></article></div>
    <div class="col-md-6 col-xl-3"><article class="metric-card"><span>OpenSky</span><strong id="openskyState">—</strong><small id="openskyDetail">posición ADS-B con ICAO24 conocido</small></article></div>
    <div class="col-md-6 col-xl-3"><article class="metric-card"><span>Meteorología</span><strong id="weatherState">—</strong><small id="weatherDetail">METAR LEZL sin clave API</small></article></div>
  </section>

  <section class="panel p-3 p-md-4 mb-4" aria-labelledby="providerHealthTitle">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
      <div><h2 class="h5 mb-1" id="providerHealthTitle">Salud, frecuencia y consumo</h2><p class="text-secondary mb-0">Distingue una fuente sana, retrasada, sin datos o detenida por error. Las consultas son recuentos locales; nunca se presentan como cuota oficial si el proveedor no comunica el saldo.</p></div>
      <span class="badge text-bg-secondary" id="healthUpdatedAt">Calculando…</span>
    </div>
    <div class="row g-3" id="providerHealthGrid"><div class="col-12 text-secondary">Cargando salud de las fuentes…</div></div>
  </section>

  <section class="panel p-3 p-md-4 mb-4">
    <div class="d-flex flex-wrap align-items-end justify-content-between gap-3 mb-3">
      <div><h2 class="h5 mb-1">Ejecutar actualizaciones</h2><p class="text-secondary mb-0">Los límites evitan agotar cuotas o bloquear el hosting.</p></div>
      <div><label class="form-label small" for="operationLimit">Máximo OpenSky</label><input class="form-control" id="operationLimit" type="number" min="1" max="25" value="10" style="width:9rem"></div>
    </div>
    <div class="row g-3">
      <div class="col-sm-6 col-xl"><button class="btn btn-primary w-100 operation-button" data-action="airlabs">Actualizar AirLabs</button></div>
      <div class="col-sm-6 col-xl"><button class="btn btn-info w-100 operation-button" data-action="opensky">Actualizar OpenSky</button></div>
      <div class="col-sm-6 col-xl"><button class="btn btn-outline-light w-100 operation-button" data-action="weather">Actualizar tiempo</button></div>
      <div class="col-sm-6 col-xl"><button class="btn btn-warning w-100 operation-button" data-action="push">🔔 Despachar avisos</button></div>
      <div class="col-sm-6 col-xl"><button class="btn btn-success w-100 operation-button" data-action="all">Actualizar fuentes</button></div>
    </div>
    <p class="small text-secondary mt-3 mb-0">Aena se recoge mediante el barrido automático de Infovuelos y prevalece en estado, sala y cinta. AirLabs consulta las llegadas en bloque, rota las claves configuradas y entra como fuente provisional; OpenSky solo añade telemetría y AviationWeather el METAR. «Despachar avisos» prueba desde la web la misma cola que ejecuta el cron.</p>
  </section>

  <section class="panel p-3 p-md-4 mb-4">
    <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-3">
      <div><span class="badge text-bg-info mb-2">Sin terminal</span><h2 class="h5 mb-1">Importar captura JSON</h2><p class="text-secondary mb-0">Sustituye el acceso web incorrecto a <code>bin/sync-json.php</code>. El lote pasa por el mismo cerebro y conserva observaciones y eventos.</p></div>
      <a class="btn btn-sm btn-outline-info" href="sync-runs.php">Ver eventos</a>
    </div>
    <div id="jsonImportAlert" class="alert d-none" role="alert"></div>
    <div class="row g-3">
      <div class="col-md-5"><label class="form-label" for="jsonFile">Fichero JSON</label><input class="form-control" id="jsonFile" type="file" accept="application/json,.json"><div class="form-text">Máximo 2 MB y 1.200 vuelos físicos.</div></div>
      <div class="col-12"><label class="form-label" for="jsonPayload">Contenido</label><textarea class="form-control font-monospace" id="jsonPayload" rows="8" spellcheck="false" placeholder='{"context":{"provider":"aena","mode":"delta"},"flights":[...]}'></textarea></div>
      <div class="col-12"><button class="btn btn-info w-100" id="importJson" type="button">Conciliar JSON ahora</button></div>
    </div>
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
  const healthLabels = {healthy:'AL DÍA',paused:'EN PAUSA',stale:'RETRASADO',error:'ERROR',unknown:'SIN DATOS',not_configured:'SIN CONFIGURAR'};
  const healthClasses = {healthy:'success',paused:'info',stale:'warning',error:'danger',unknown:'secondary',not_configured:'secondary'};
  const state = value => value ? '<span class="text-success">LISTO</span>' : '<span class="text-danger">PENDIENTE</span>';
  const formatAge = minutes => minutes === null || minutes === undefined ? 'sin lecturas' : minutes < 1 ? 'ahora' : minutes < 60 ? `hace ${minutes} min` : `hace ${Math.floor(minutes / 60)} h ${minutes % 60} min`;
  const compactError = value => String(value || '').length > 180 ? String(value).slice(0, 177) + '…' : String(value || '');

  function renderProviderHealth(items, serverTime) {
    const byProvider = Object.fromEntries(items.map(item => [item.provider, item]));
    const bindings = {aena:['#aenaState','#aenaDetail'],airlabs:['#airlabsState','#airlabsDetail'],opensky:['#openskyState','#openskyDetail'],aviationweather:['#weatherState','#weatherDetail']};
    Object.entries(bindings).forEach(([provider, selectors]) => {
      const item = byProvider[provider]; if (!item) return;
      const color = healthClasses[item.status] || 'secondary';
      document.querySelector(selectors[0]).innerHTML = `<span class="text-${color}">${healthLabels[item.status] || item.status}</span>`;
      document.querySelector(selectors[1]).textContent = `${formatAge(item.age_minutes)} · ${item.last_records} registros`;
    });

    document.querySelector('#providerHealthGrid').innerHTML = items.map(item => {
      const color = healthClasses[item.status] || 'secondary';
      const modes = item.provider === 'aena' ? `<div class="provider-health-modes">
        <span>Directo: <b>${item.modes?.live?.at ? esc(item.modes.live.at) : 'sin captura'}</b></span>
        <span>Semanal: <b>${item.modes?.week?.at ? esc(item.modes.week.at) : 'sin captura'}</b></span>
      </div>` : '';
      return `<div class="col-md-6 col-xl-3"><article class="provider-health-card provider-health-${esc(item.status)} h-100">
        <header><div><small>${esc(item.label)}</small><strong>${esc(healthLabels[item.status] || item.status)}</strong></div><span class="badge text-bg-${color}">${item.rate_limited ? 'CUOTA' : esc(formatAge(item.age_minutes))}</span></header>
        <dl><div><dt>Último intento</dt><dd>${esc(item.last_attempt_at || 'Todavía no')}</dd></div><div><dt>Último éxito</dt><dd>${esc(item.last_success_at || 'Todavía no')}</dd></div><div><dt>Próximo esperado</dt><dd>${esc(item.next_expected_at || 'Sin referencia')}</dd></div><div><dt>Hoy</dt><dd>${Number(item.attempts_today || 0).toLocaleString('es-ES')} intentos</dd></div></dl>
        ${modes}<p class="provider-quota-note">${esc(item.quota_note)}</p>
        ${item.last_error ? `<p class="provider-error"><b>Último error:</b> ${esc(compactError(item.last_error))}</p>` : ''}
      </article></div>`;
    }).join('');
    document.querySelector('#healthUpdatedAt').textContent = `Servidor ${serverTime || 'sin hora'}`;
  }

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
      const airlabsKeys = Number(data.providers.airlabs_keys || 0);
      document.querySelector('#airlabsDetail').textContent = data.providers.airlabs
        ? `consulta agrupada · ${airlabsKeys} clave${airlabsKeys === 1 ? '' : 's'}`
        : 'sin claves configuradas';
      document.querySelector('#openskyState').innerHTML = state(data.providers.opensky);
      document.querySelector('#weatherState').innerHTML = state(data.providers.aviationweather);
      renderProviderHealth(data.provider_health || [], data.server_time);
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

  async function importJson() {
    const button = document.querySelector('#importJson');
    const alert = document.querySelector('#jsonImportAlert');
    const payload = document.querySelector('#jsonPayload').value.trim();
    if (!payload) {
      alert.className = 'alert alert-warning'; alert.textContent = 'Selecciona un fichero o pega el JSON.'; return;
    }
    const form = new FormData();
    form.set('csrf', csrf);
    form.set('payload', payload);
    form.set('filename', document.querySelector('#jsonFile').files[0]?.name || 'captura-web.json');
    const original = button.textContent; button.disabled = true;
    button.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Conciliando';
    try {
      const response = await fetch('api/sync-json.php', {method:'POST', body:form, headers:{Accept:'application/json'}});
      const data = await response.json();
      if (!response.ok || data.ok === false) throw new Error(data.error || 'No se pudo importar el JSON.');
      alert.className = 'alert alert-success'; alert.textContent = data.message;
      await loadStatus();
    } catch (error) {
      alert.className = 'alert alert-danger'; alert.textContent = error.message;
    } finally { button.disabled = false; button.textContent = original; }
  }

  document.querySelector('#jsonFile').addEventListener('change', async event => {
    const file = event.target.files[0];
    if (!file) return;
    if (file.size > 2 * 1024 * 1024) {
      const alert = document.querySelector('#jsonImportAlert'); alert.className='alert alert-danger'; alert.textContent='El fichero supera 2 MB.'; event.target.value=''; return;
    }
    document.querySelector('#jsonPayload').value = await file.text();
  });
  document.querySelector('#importJson').addEventListener('click', importJson);

  document.querySelectorAll('.operation-button').forEach(button => button.addEventListener('click', () => run(button)));
  document.querySelector('#refreshStatus').addEventListener('click', loadStatus);
  loadStatus();
})();
</script>
</body>
</html>
