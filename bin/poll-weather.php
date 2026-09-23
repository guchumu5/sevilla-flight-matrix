<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';

use SevillaMatrix\Api\AviationWeatherClient;
use SevillaMatrix\Database;

try {
    $data=(new AviationWeatherClient())->metar('LEZL'); if(!$data){exit("Sin METAR nuevo\n");}
    $stmt=Database::connection()->prepare("INSERT IGNORE INTO weather_observations (station,observed_at,wind_direction,wind_speed_kt,gust_kt,raw_metar,raw_data) VALUES ('LEZL',?,?,?,?,?,?)");
    $observed=!empty($data['obsTime'])?date('Y-m-d H:i:s',(int)$data['obsTime']):date('Y-m-d H:i:s');
    $stmt->execute([$observed,$data['wdir']??null,$data['wspd']??null,$data['wgst']??null,$data['rawOb']??null,json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
    echo date('c')." METAR LEZL actualizado\n";
} catch(Throwable $e){fwrite(STDERR,date('c')." {$e->getMessage()}\n");exit(1);}

