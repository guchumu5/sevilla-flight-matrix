<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';

use SevillaMatrix\Database;

try {
    $pdo=Database::connection();
    $sql=file_get_contents(PROJECT_ROOT.'/database/schema.sql');
    $sql=preg_replace('/CREATE DATABASE.*?;|USE\s+sevilla_matrix\s*;/is','',$sql??'');
    $pdo->exec($sql);
    if(in_array('--demo',$argv,true)){$demo=file_get_contents(PROJECT_ROOT.'/database/demo.sql');$demo=preg_replace('/USE\s+sevilla_matrix\s*;/i','',$demo??'');$pdo->exec($demo);}
    echo "Instalación completada".(in_array('--demo',$argv,true)?" con datos demo":"").".\n";
} catch(Throwable $e){fwrite(STDERR,"Error: {$e->getMessage()}\n");exit(1);}

