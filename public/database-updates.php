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
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Actualizaciones MySQL · Matriz Sevilla</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="assets/css/app.css" rel="stylesheet">
</head>
<body>
<nav class="navbar app-navbar border-bottom">
  <div class="container-fluid px-lg-4">
    <a class="navbar-brand" href="admin.php">← Administración</a>
    <div class="d-flex gap-2">
      <a class="btn btn-sm btn-outline-light" href="index.php">Tablero</a>
      <a class="btn btn-sm btn-outline-light" href="logout.php">Salir</a>
    </div>
  </div>
</nav>

<main class="container py-4" data-csrf="<?= e(Auth::csrf()) ?>">
  <section class="panel p-3 p-md-4 mb-4">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
      <div>
        <span class="badge text-bg-primary mb-2">Actualizador interno</span>
        <h1 class="h3 mb-2">Actualizaciones de MySQL</h1>
        <p class="text-secondary mb-0">Ejecuta únicamente paquetes versionados incluidos en la aplicación y comprueba su huella. No admite SQL libre ni archivos subidos desde el navegador.</p>
      </div>
      <button class="btn btn-outline-light" id="refreshUpdates" type="button">Comprobar de nuevo</button>
    </div>
  </section>

  <div id="updateAlert" class="alert d-none" role="alert"></div>

  <section class="row g-3 mb-4" aria-label="Resumen de actualizaciones">
    <div class="col-4"><article class="metric-card"><span>Pendientes</span><strong id="pendingCount">—</strong><small>listas para aplicar</small></article></div>
    <div class="col-4"><article class="metric-card"><span>Aplicadas</span><strong id="appliedCount">—</strong><small>registradas en MySQL</small></article></div>
    <div class="col-4"><article class="metric-card metric-orange"><span>Modificadas</span><strong id="modifiedCount">—</strong><small>requieren revisión</small></article></div>
  </section>

  <section class="panel overflow-hidden mb-4">
    <header class="panel-header">
      <h2 class="h5 mb-1">Paquetes disponibles</h2>
      <p class="text-secondary mb-0">Cada ejecución queda anotada con fecha, huella, duración y número de sentencias.</p>
    </header>
    <div id="updatesLoading" class="loading-state"><div class="spinner-border text-success" role="status"></div><span>Consultando MySQL…</span></div>
    <div class="table-responsive d-none" id="updatesTableWrap">
      <table class="table align-middle mb-0">
        <thead><tr><th>Estado</th><th>Actualización</th><th>Contenido</th><th>Control</th><th class="text-end">Acción</th></tr></thead>
        <tbody id="updatesBody"></tbody>
      </table>
    </div>
    <div class="empty-state d-none" id="updatesEmpty"><strong>No hay paquetes disponibles.</strong><span>La aplicación está preparada para recibir próximas actualizaciones.</span></div>
  </section>

  <section class="panel p-3 p-md-4">
    <h2 class="h5">Protección antes de ejecutar</h2>
    <p class="text-secondary">La aplicación bloquea ejecuciones simultáneas y no repite una actualización ya aplicada. Las modificaciones estructurales de MySQL pueden no ser reversibles automáticamente.</p>
    <div class="form-check">
      <input class="form-check-input" type="checkbox" id="backupConfirmation">
      <label class="form-check-label" for="backupConfirmation">Confirmo que dispongo de una copia de seguridad reciente o acepto aplicar esta actualización sobre la base actual.</label>
    </div>
  </section>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
(() => {
  'use strict';
  const main = document.querySelector('main');
  const csrf = main.dataset.csrf;
  const body = document.querySelector('#updatesBody');
  const table = document.querySelector('#updatesTableWrap');
  const empty = document.querySelector('#updatesEmpty');
  const loading = document.querySelector('#updatesLoading');
  const alertBox = document.querySelector('#updateAlert');
  const confirmBox = document.querySelector('#backupConfirmation');
  const esc = value => String(value ?? '').replace(/[&<>'"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));

  function badge(state) {
    if (state === 'applied') return '<span class="badge text-bg-success">Aplicada</span>';
    if (state === 'modified') return '<span class="badge text-bg-warning">Modificada</span>';
    return '<span class="badge text-bg-primary">Pendiente</span>';
  }

  function showAlert(message, ok = false) {
    alertBox.className = `alert ${ok ? 'alert-success' : 'alert-danger'}`;
    alertBox.textContent = message;
    alertBox.scrollIntoView({behavior: 'smooth', block: 'center'});
  }

  async function loadUpdates() {
    loading.classList.remove('d-none');
    table.classList.add('d-none');
    empty.classList.add('d-none');
    try {
      const response = await fetch('api/database-updates.php', {headers:{Accept:'application/json'}, cache:'no-store'});
      const payload = await response.json();
      if (!response.ok) throw new Error(payload.error || 'No se pudieron consultar las actualizaciones.');
      document.querySelector('#pendingCount').textContent = payload.pending_count;
      document.querySelector('#appliedCount').textContent = payload.applied_count;
      document.querySelector('#modifiedCount').textContent = payload.modified_count;
      body.innerHTML = payload.updates.map(item => `<tr>
        <td>${badge(item.state)}</td>
        <td><strong>${esc(item.name)}</strong><span class="meta d-block">${esc(item.id)} · ${esc(item.category)}</span></td>
        <td>${esc(item.description)}<span class="meta d-block">${Number(item.statement_count).toLocaleString('es-ES')} sentencias · huella ${esc(item.checksum)}</span></td>
        <td>${item.applied_at ? `<strong>${esc(item.applied_at)}</strong><span class="meta d-block">${Number(item.execution_ms || 0).toLocaleString('es-ES')} ms · ${Number(item.statements_executed || 0).toLocaleString('es-ES')} ejecutadas</span>` : '<span class="text-secondary">Sin ejecutar</span>'}</td>
        <td class="text-end">${item.state === 'pending' ? `<button class="btn btn-success apply-update" data-id="${esc(item.id)}" type="button">Aplicar</button>` : item.state === 'modified' ? '<span class="text-warning">Crear una actualización nueva</span>' : '<span class="text-success">✓ Completada</span>'}</td>
      </tr>`).join('');
      table.classList.toggle('d-none', payload.updates.length === 0);
      empty.classList.toggle('d-none', payload.updates.length !== 0);
      document.querySelectorAll('.apply-update').forEach(button => button.addEventListener('click', () => applyUpdate(button)));
    } catch (error) {
      showAlert(error.message);
    } finally {
      loading.classList.add('d-none');
    }
  }

  async function applyUpdate(button) {
    if (!confirmBox.checked) {
      showAlert('Antes de aplicar, confirma la copia de seguridad en la parte inferior.');
      return;
    }
    if (!window.confirm('¿Aplicar esta actualización a MySQL ahora?')) return;

    const original = button.textContent;
    button.disabled = true;
    button.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Aplicando';
    try {
      const data = new FormData();
      data.set('csrf', csrf);
      data.set('update_id', button.dataset.id);
      data.set('confirm_backup', '1');
      const response = await fetch('api/database-updates.php', {method:'POST', body:data, headers:{Accept:'application/json'}});
      const payload = await response.json();
      if (!response.ok) throw new Error(payload.error || 'No se pudo aplicar la actualización.');
      showAlert(`${payload.result.message} ${payload.result.statements_executed || 0} sentencias ejecutadas.`, true);
      confirmBox.checked = false;
      await loadUpdates();
    } catch (error) {
      showAlert(error.message);
      button.disabled = false;
      button.textContent = original;
    }
  }

  document.querySelector('#refreshUpdates').addEventListener('click', loadUpdates);
  loadUpdates();
})();
</script>
</body>
</html>
