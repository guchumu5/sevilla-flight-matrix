<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';

use SevillaMatrix\Api\OpenSkyClient;
use SevillaMatrix\Database;
use SevillaMatrix\Env;
use SevillaMatrix\FlightRepository;

$pdo = Database::connection(); $repo = new FlightRepository($pdo);
$client = new OpenSkyClient(Env::get('OPENSKY_CLIENT_ID','')??'', Env::get('OPENSKY_CLIENT_SECRET','')??'', PROJECT_ROOT.'/storage/cache/opensky-token.json');
$insert = $pdo->prepare("INSERT INTO observations (flight_id,source,observed_at,status,latitude,longitude,altitude_m,ground_speed_ms,track_deg,vertical_rate_ms,raw_data) VALUES (?,'opensky',NOW(),?,?,?,?,?,?,?,?)");
foreach ($repo->trackedAircraft() as $flight) {
    try {
        $state=$client->state($flight['aircraft_icao24']); if(!$state) continue;
        $insert->execute([(int)$flight['id'],$state['on_ground']?'En tierra':'En vuelo',$state['latitude'],$state['longitude'],$state['altitude_m'],$state['ground_speed_ms'],$state['track_deg'],$state['vertical_rate_ms'],json_encode($state['raw'])]);
        echo date('c')." {$flight['physical_flight']} ADS-B actualizado\n";
    } catch(Throwable $e){fwrite(STDERR,date('c')." {$flight['physical_flight']}: {$e->getMessage()}\n");}
}

