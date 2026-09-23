<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';

use SevillaMatrix\Auth;

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (Auth::attempt((string)($_POST['password'] ?? ''))) {
        header('Location: admin.php'); exit;
    }
    $error = 'Contraseña incorrecta o no configurada.';
}
?>
<!doctype html><html lang="es" data-bs-theme="dark"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Acceso · Matriz Sevilla</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="assets/css/app.css" rel="stylesheet"></head>
<body class="d-flex align-items-center justify-content-center p-3">
<main class="panel p-4 w-100" style="max-width:420px">
  <a href="index.php" class="text-decoration-none text-secondary">← Volver al tablero</a>
  <h1 class="h4 mt-3">Confirmación Aena</h1><p class="text-secondary">Acceso reservado para registrar datos oficiales.</p>
  <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
  <form method="post"><label class="form-label" for="password">Contraseña</label>
    <input class="form-control mb-3" id="password" name="password" type="password" required autofocus>
    <button class="btn btn-success w-100">Entrar</button></form>
</main></body></html>

