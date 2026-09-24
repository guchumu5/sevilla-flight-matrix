<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';

use SevillaMatrix\Auth;
use SevillaMatrix\Database;
use SevillaMatrix\FlightRepository;

if (!Auth::check()) { header('Location: login.php'); exit; }
$repo = new FlightRepository(Database::connection());
$flights = $repo->board($_GET['date'] ?? date('Y-m-d'));
?>
<!doctype html><html lang="es" data-bs-theme="dark"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Aena / Admin · Matriz Sevilla</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="assets/css/app.css" rel="stylesheet"></head><body>
<nav class="navbar app-navbar border-bottom"><div class="container-fluid px-lg-4"><a class="navbar-brand" href="index.php">← Matriz de Cintas</a><div class="d-flex flex-wrap gap-2"><a class="btn btn-sm btn-outline-info" href="sync-runs.php">Cerebro de eventos</a><a class="btn btn-sm btn-outline-info" href="database-updates.php">Actualizar MySQL</a><a class="btn btn-sm btn-outline-light" href="logout.php">Salir</a></div></div></nav>
<main class="container py-4">
<section class="panel p-3 p-md-4 mb-4">
  <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3"><div><h1 class="h4 mb-1">Añadir vuelo físico</h1><p class="text-secondary mb-0">Los códigos compartidos se guardan dentro del mismo vuelo.</p></div><span class="badge text-bg-secondary">Paso 1</span></div>
  <div id="flightAlert" class="alert d-none"></div>
  <form id="flightForm" class="row g-3">
    <input type="hidden" name="csrf" value="<?= e(Auth::csrf()) ?>">
    <div class="col-md-3"><label class="form-label">Fecha</label><input class="form-control" type="date" name="flight_date" value="<?= e(date('Y-m-d')) ?>" required></div>
    <div class="col-md-3"><label class="form-label">Vuelo físico</label><input class="form-control text-uppercase" name="physical_flight" placeholder="VLG3049" required maxlength="20"></div>
    <div class="col-md-3"><label class="form-label">Código compartido</label><input class="form-control text-uppercase" name="codeshare" placeholder="IBE5354" maxlength="20"></div>
    <div class="col-md-3"><label class="form-label">Llegada programada</label><input class="form-control" type="time" name="scheduled_time" required></div>
    <div class="col-md-2"><label class="form-label">IATA origen</label><input class="form-control text-uppercase" name="origin_iata" placeholder="LPA" maxlength="3" required></div>
    <div class="col-md-4"><label class="form-label">Origen</label><input class="form-control" name="origin_name" placeholder="Gran Canaria" maxlength="100" required></div>
    <div class="col-md-3"><label class="form-label">Tráfico</label><select class="form-select" name="traffic_class"><option value="domestico">Doméstico</option><option value="schengen">Schengen</option><option value="no_schengen">No Schengen</option><option value="desconocido">Desconocido</option></select></div>
    <div class="col-md-3"><label class="form-label">Capacidad teórica</label><input class="form-control" type="number" name="capacity" min="1" max="600" placeholder="186"></div>
    <div class="col-12"><button class="btn btn-outline-success w-100" type="submit">Añadir o actualizar vuelo</button></div>
  </form>
</section>
<div class="row g-4">
  <div class="col-lg-7"><section class="panel p-3 p-md-4">
    <div class="d-flex align-items-center justify-content-between"><h1 class="h4">Registrar observación oficial</h1><span class="badge text-bg-secondary">Paso 2</span></div>
    <p class="text-secondary">Cada envío añade una observación. El histórico anterior nunca se borra.</p>
    <div id="adminAlert" class="alert d-none"></div>
    <form id="observationForm" class="row g-3">
      <input type="hidden" name="csrf" value="<?= e(Auth::csrf()) ?>">
      <div class="col-12"><label class="form-label">Vuelo</label><select class="form-select" name="flight_id" required>
        <option value="">Selecciona…</option><?php foreach($flights as $f): ?><option value="<?= (int)$f['id'] ?>"><?= e(date('H:i', strtotime($f['scheduled_arrival'])) . ' · ' . $f['origin_name'] . ' · ' . $f['physical_flight']) ?></option><?php endforeach; ?>
      </select></div>
      <div class="col-md-6"><label class="form-label">Estado</label><select class="form-select" name="status"><option>Programado</option><option>En vuelo</option><option>Aproximándose</option><option>Aterrizado</option><option>Entrega de equipaje</option><option>Finalizado</option><option>Cancelado</option></select></div>
      <div class="col-md-6"><label class="form-label">ETA</label><input class="form-control" type="datetime-local" name="eta"></div>
      <div class="col-4"><label class="form-label">Sala</label><select class="form-select" name="hall"><option value="">—</option><option>A</option><option>B</option></select></div>
      <div class="col-4"><label class="form-label">Cinta</label><select class="form-select" name="belt"><option value="">STAND BY</option><?php for($i=1;$i<=8;$i++): ?><option><?= $i ?></option><?php endfor; ?></select></div>
      <div class="col-4"><label class="form-label">Puerta</label><input class="form-control" name="gate" maxlength="15"></div>
      <div class="col-md-6"><label class="form-label">Llegada real</label><input class="form-control" type="datetime-local" name="actual_arrival"></div>
      <div class="col-md-6"><label class="form-label">Equipaje</label><select class="form-select" name="baggage_state"><option value="pendiente">Pendiente</option><option value="entrega">En entrega</option><option value="finalizado">Finalizado</option></select></div>
      <div class="col-md-6"><label class="form-label">Ocupación</label><select class="form-select" name="occupancy_level"><option value="no_verificable">No verificable</option><option value="baja">Baja</option><option value="media">Media</option><option value="alta">Alta</option></select></div>
      <div class="col-12"><button class="btn btn-success w-100" type="submit">Guardar observación Aena</button></div>
    </form>
  </section></div>
  <div class="col-lg-5"><aside class="panel p-3 p-md-4"><h2 class="h5">Criterio de autoridad</h2><p>Si Aena y una API secundaria discrepan, este registro oficial prevalece en:</p><ul class="text-secondary"><li>Estado operativo</li><li>Sala de recogida</li><li>Cinta de equipajes</li><li>Entrega y finalización</li></ul><p class="mb-0 text-secondary">La posición ADS-B y la meteorología permanecen como datos complementarios.</p></aside></div>
</div></main>
<script>
document.querySelector('#observationForm').addEventListener('submit', async event => {
  event.preventDefault(); const form=event.currentTarget, alert=document.querySelector('#adminAlert');
  const response=await fetch('api/aena-observation.php',{method:'POST',body:new FormData(form)}); const data=await response.json();
  alert.className='alert '+(response.ok?'alert-success':'alert-danger'); alert.textContent=response.ok?'Observación guardada correctamente.':(data.error||'No se pudo guardar.');
  if(response.ok) form.reset();
});
document.querySelector('#flightForm').addEventListener('submit', async event => {
  event.preventDefault(); const form=event.currentTarget, alert=document.querySelector('#flightAlert');
  const response=await fetch('api/create-flight.php',{method:'POST',body:new FormData(form)}); const data=await response.json();
  alert.className='alert '+(response.ok?'alert-success':'alert-danger'); alert.textContent=response.ok?'Vuelo guardado. Recarga la página para seleccionarlo.':(data.error||'No se pudo guardar.');
});
</script></body></html>
