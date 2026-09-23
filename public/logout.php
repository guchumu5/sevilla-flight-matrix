<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
SevillaMatrix\Auth::logout();
header('Location: login.php');

