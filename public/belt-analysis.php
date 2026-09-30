<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';

use SevillaMatrix\Auth;

if (!Auth::check()) { header('Location: login.php'); exit; }
$defaultDate = date('Y-m-d', strtotime('-1 day'));
?>
<!doctype html>
<html lang="es" data-bs-theme="dark">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Motivos de cambios de cinta · Matriz Sevilla</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="assets/css/app.css" rel="stylesheet">
</head>
<body>
<nav class="navbar app-navbar border-bottom"><div class="container-fluid px-lg-4">
  <a class="navbar-brand" href="admin.php">← Administración</a>
  <div class="d-flex flex-wrap gap-2"><a class="btn btn-sm btn-outline-info" href="sync-runs.php">Cerebro</a><a class="btn btn-sm btn-outline-light" href="index.php">Tablero</a><a class="btn btn-sm btn-outline-light" href="logout.php">Salir</a></div>
</div></nav>

<main class="container-fluid px-3 px-lg-4 py-4" data-csrf="<?= e(Auth::csrf()) ?>">
  <section class="panel p-3 p-md-4 mb-4">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
      <div><span class="badge text-bg-warning mb-2">Análisis diario</span><h1 class="h3 mb-2">Motivos de cambios de cinta</h1>
      <p class="text-secondary mb-0">Conserva el cambio oficial y añade una explicación trazable. Una inferencia contextual nunca se presenta como causa publicada por Aena.</p></div>
      <div class="d-flex flex-wrap align-items-end gap-2">
        <div><label class="form-label" for="analysisDate">Día de los vuelos</label><input class="form-control" id="analysisDate" type="date" value="<?= e($defaultDate) ?>"></div>
        <button class="btn btn-success" id="runAnalysis" type="button">Analizar día</button>
        <button class="btn btn-outline-light" id="refreshAnalysis" type="button">Consultar</button>
      </div>
    </div>
  </section>
  <div class="alert d-none" id="analysisAlert" role="alert"></div>
  <section class="row g-3 mb-4">
    <div class="col-6 col-lg-3"><article class="metric-card"><span>Cambios analizados</span><strong id="analysisCount">—</strong><small>para la fecha elegida</small></article></div>
    <div class="col-6 col-lg-3"><article class="metric-card metric-blue"><span>Motivo probable</span><strong id="probableCount">—</strong><small>evidencia contextual</small></article></div>
    <div class="col-6 col-lg-3"><article class="metric-card metric-orange"><span>Provisional</span><strong id="provisionalCount">—</strong><small>no permite asegurar causa</small></article></div>
    <div class="col-6 col-lg-3"><article class="metric-card"><span>Publicado</span><strong id="officialCount">—</strong><small>causa aportada por la fuente</small></article></div>
  </section>
  <section class="panel overflow-hidden mb-4">
    <header class="panel-header"><h2 class="h5 mb-1">Patrones de los últimos 90 días</h2><p class="text-secondary mb-0">Frecuencia de las explicaciones; sirve para detectar recurrencias, no para demostrar por sí sola una causa.</p></header>
    <div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Patrón</th><th>Casos</th><th>Canarias</th><th>Confianza</th><th>Último</th></tr></thead><tbody id="patternsBody"></tbody></table></div>
  </section>
  <section class="panel overflow-hidden mb-4">
    <header class="panel-header"><h2 class="h5 mb-1">Histórico consultable</h2><p class="text-secondary mb-0">Cada fila mantiene el cambio Aena, el motivo calculado, la confianza y las evidencias de contexto.</p></header>
    <div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Vuelo</th><th>Cambio oficial</th><th>Clasificación</th><th>Explicación</th><th>Evidencia</th></tr></thead><tbody id="analysisBody"><tr><td colspan="5" class="text-center py-5 text-secondary">Cargando…</td></tr></tbody></table></div>
  </section>
  <section class="panel overflow-hidden">
    <header class="panel-header"><h2 class="h5 mb-1">Ejecuciones diarias</h2><p class="text-secondary mb-0">El mismo día y versión del modelo no se duplican.</p></header>
    <div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Día</th><th>Estado</th><th>Cambios</th><th>Modelo</th><th>Finalización</th></tr></thead><tbody id="runsBody"></tbody></table></div>
  </section>
</main>
<script>
(() => {
  'use strict';
  const main=document.querySelector('main'), dateInput=document.querySelector('#analysisDate');
  const esc=value=>String(value??'').replace(/[&<>'"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));
  const badge=value=>`<span class="badge ${value==='confirmado'?'text-bg-success':value==='probable'?'text-bg-warning':'text-bg-secondary'}">${esc(value)}</span>`;
  function show(message,ok=false){const box=document.querySelector('#analysisAlert');box.className=`alert ${ok?'alert-success':'alert-danger'}`;box.textContent=message;}
  function evidence(item){
    const e=item.evidence||{}, bits=[];
    if(e.deviation_minutes!==null&&e.deviation_minutes!==undefined) bits.push(`desviación ${Number(e.deviation_minutes)} min`);
    if(e.minimum_previous_belt_interval_minutes!==null&&e.minimum_previous_belt_interval_minutes!==undefined) bits.push(`intervalo ${Number(e.minimum_previous_belt_interval_minutes)} min`);
    if(e.new_hall_flights_30m) bits.push(`${Number(e.new_hall_flights_30m)} vuelos en sala`);
    if(e.new_hall_capacity_30m) bits.push(`hasta ${Number(e.new_hall_capacity_30m).toLocaleString('es-ES')} plazas`);
    return bits.length?bits.join(' · '):'Sin evidencia adicional suficiente';
  }
  async function load(){
    const response=await fetch(`api/belt-analysis.php?date=${encodeURIComponent(dateInput.value)}&limit=500`,{cache:'no-store'}), data=await response.json();
    if(!response.ok) throw new Error(data.error||'No se pudo consultar el análisis.');
    const rows=data.analyses||[];
    document.querySelector('#analysisCount').textContent=rows.length;
    document.querySelector('#probableCount').textContent=rows.filter(x=>x.confidence==='probable').length;
    document.querySelector('#provisionalCount').textContent=rows.filter(x=>x.confidence==='provisional').length;
    document.querySelector('#officialCount').textContent=rows.filter(x=>Number(x.official_reason)===1).length;
    document.querySelector('#analysisBody').innerHTML=rows.length?rows.map(item=>`<tr class="${Number(item.is_canary)===1?'table-warning':''}">
      <td><strong>${esc(item.physical_flight)}</strong><span class="meta d-block">${esc(item.origin_name)} · ${esc(item.analysis_date)}</span></td>
      <td><strong>${esc(item.before_value||'sin cinta')} → ${esc(item.after_value||'retirada')}</strong><span class="meta d-block">Aena · ${esc(item.detected_at)}</span></td>
      <td>${badge(item.confidence)}<span class="meta d-block">${Number(item.official_reason)===1?'causa publicada':'inferencia matriz'}</span></td>
      <td><strong>${esc(item.reason_label)}</strong><span class="meta d-block">${esc(item.reason_detail)}</span></td>
      <td>${esc(evidence(item))}<span class="meta d-block">${esc(item.model_version)}</span></td></tr>`).join(''):'<tr><td colspan="5" class="text-center py-5 text-secondary">No hay cambios analizados para este día.</td></tr>';
    const runs=data.runs||[];
    const patterns=data.patterns||[];
    document.querySelector('#patternsBody').innerHTML=patterns.length?patterns.map(item=>`<tr><td><strong>${esc(item.reason_label)}</strong><span class="meta d-block">${esc(item.reason_code)} · ${Number(item.official_reason)===1?'publicado':'inferido'}</span></td><td><strong>${Number(item.cases_count||0)}</strong></td><td><strong>${Number(item.canary_count||0)}</strong></td><td>${badge(item.confidence)}</td><td>${esc(item.last_seen)}</td></tr>`).join(''):'<tr><td colspan="5" class="text-center py-4 text-secondary">Todavía no hay muestra suficiente.</td></tr>';
    document.querySelector('#runsBody').innerHTML=runs.length?runs.map(run=>`<tr><td><strong>${esc(run.analysis_date)}</strong></td><td><span class="badge ${run.status==='success'?'text-bg-success':run.status==='failed'?'text-bg-danger':'text-bg-primary'}">${esc(run.status)}</span></td><td>${Number(run.changes_found||0)} encontrados · ${Number(run.analyses_created||0)} nuevos</td><td>${esc(run.model_version)}</td><td>${esc(run.finished_at||run.error_message||'en curso')}</td></tr>`).join(''):'<tr><td colspan="5" class="text-center py-4 text-secondary">Todavía no hay ejecuciones.</td></tr>';
  }
  async function run(){
    const button=document.querySelector('#runAnalysis');button.disabled=true;
    try{const response=await fetch('api/belt-analysis.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({csrf:main.dataset.csrf,date:dateInput.value})}),data=await response.json();if(!response.ok)throw new Error(data.error||'No se pudo ejecutar.');show(data.result.message,true);await load();}
    catch(error){show(error.message);}finally{button.disabled=false;}
  }
  document.querySelector('#runAnalysis').addEventListener('click',run);
  document.querySelector('#refreshAnalysis').addEventListener('click',()=>load().catch(error=>show(error.message)));
  dateInput.addEventListener('change',()=>load().catch(error=>show(error.message)));
  load().catch(error=>show(error.message));
})();
</script>
</body></html>
