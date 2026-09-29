<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
use SevillaMatrix\Auth;
if (!Auth::check()) { header('Location: login.php'); exit; }
?>
<!doctype html><html lang="es" data-bs-theme="dark"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Copias · Matriz Sevilla</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"><link href="assets/css/app.css" rel="stylesheet"></head><body>
<nav class="navbar app-navbar border-bottom"><div class="container-fluid px-lg-4"><a class="navbar-brand" href="admin.php">← Administración</a><a class="btn btn-sm btn-outline-light" href="logout.php">Salir</a></div></nav>
<main class="container py-4" data-csrf="<?= e(Auth::csrf()) ?>">
<section class="panel p-3 p-md-4 mb-4"><div class="d-flex flex-wrap justify-content-between gap-3"><div><span class="badge text-bg-success mb-2">Protección MySQL</span><h1 class="h3">Copias de seguridad</h1><p class="text-secondary mb-0">Copias comprimidas, suma SHA-256 y retención automática: 7 diarias, 5 semanales y 12 mensuales.</p></div><button class="btn btn-success" id="createBackup">Crear copia ahora</button></div></section>
<div id="backupAlert" class="alert d-none"></div>
<section class="panel overflow-hidden"><header class="panel-header"><h2 class="h5 mb-1">Copias disponibles</h2><p class="text-secondary mb-0">Verificar recorre el fichero completo sin modificar MySQL.</p></header><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Fecha</th><th>Tipo</th><th>Tamaño</th><th>Integridad</th><th></th></tr></thead><tbody id="backupRows"><tr><td colspan="5" class="text-secondary">Cargando…</td></tr></tbody></table></div></section>
<section class="alert alert-info mt-4"><strong>Tarea diaria recomendada en Plesk</strong><code class="d-block text-wrap mt-2">15 3 * * * /opt/plesk/php/8.3/bin/php /var/www/vhosts/ojito.top/httpdocs/bin/backup.php &gt;&gt; /var/www/vhosts/ojito.top/httpdocs/storage/logs/backup.log 2&gt;&amp;1</code></section>
</main><script>
(()=>{'use strict';const main=document.querySelector('main'),rows=document.querySelector('#backupRows'),alertBox=document.querySelector('#backupAlert'),csrf=main.dataset.csrf,esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const size=n=>n>1048576?(n/1048576).toFixed(1)+' MB':(n/1024).toFixed(1)+' KB';
async function load(){const r=await fetch('api/backups.php',{cache:'no-store'}),d=await r.json();if(!r.ok)throw new Error(d.error);rows.innerHTML=d.backups.length?d.backups.map(b=>`<tr><td>${esc(b.created_at)}</td><td>${esc(b.kind)}</td><td>${size(Number(b.size_bytes))}</td><td>${b.verified===true?'<span class="badge text-bg-success">Verificada</span>':'<span class="text-secondary">Pendiente</span>'}</td><td class="text-end"><button class="btn btn-sm btn-outline-info" data-verify="${esc(b.name)}">Verificar</button> <a class="btn btn-sm btn-outline-light" href="api/backups.php?download=${encodeURIComponent(b.name)}">Descargar</a></td></tr>`).join(''):'<tr><td colspan="5" class="text-secondary">Todavía no hay copias.</td></tr>';}
async function action(action,name=''){const f=new FormData();f.set('csrf',csrf);f.set('action',action);if(name)f.set('name',name);const r=await fetch('api/backups.php',{method:'POST',body:f}),d=await r.json();if(!r.ok)throw new Error(d.error);alertBox.className='alert alert-success';alertBox.textContent=action==='create'?'Copia creada y verificada.':'Integridad correcta: '+d.backup.rows+' filas.';await load();}
document.querySelector('#createBackup').onclick=async e=>{e.currentTarget.disabled=true;try{await action('create')}catch(x){alertBox.className='alert alert-danger';alertBox.textContent=x.message}finally{e.currentTarget.disabled=false}};
rows.onclick=async e=>{const b=e.target.closest('[data-verify]');if(!b)return;b.disabled=true;try{await action('verify',b.dataset.verify)}catch(x){alertBox.className='alert alert-danger';alertBox.textContent=x.message}finally{b.disabled=false}};load().catch(x=>{alertBox.className='alert alert-danger';alertBox.textContent=x.message});})();
</script></body></html>
