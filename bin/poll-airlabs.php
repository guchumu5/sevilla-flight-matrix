<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';

use SevillaMatrix\Api\AirLabsClient;
use SevillaMatrix\Database;
use SevillaMatrix\Env;
use SevillaMatrix\FlightRepository;

$pdo = Database::connection(); $repo = new FlightRepository($pdo);
$client = new AirLabsClient(Env::get('AIRLABS_API_KEY', '') ?? '');
$stmt = $pdo->query("SELECT * FROM flights WHERE flight_date BETWEEN CURRENT_DATE() AND DATE_ADD(CURRENT_DATE(), INTERVAL 1 DAY)");
foreach ($stmt->fetchAll() as $flight) {
    try {
        $data = $client->flight($flight['physical_flight']); if (!$data) continue;
        $repo->addObservation((int)$flight['id'], [
            'source'=>'airlabs','observed_at'=>date('Y-m-d H:i:s'),'status'=>$data['status']??'',
            'eta'=>$data['arr_estimated']??null,'actual_departure'=>$data['dep_actual']??null,
            'actual_arrival'=>$data['arr_actual']??null,'hall'=>$data['arr_terminal']??'',
            'belt'=>$data['arr_baggage']??'','gate'=>$data['arr_gate']??'','stand'=>null,
            'baggage_state'=>null,'occupancy_level'=>'no_verificable',
            'raw_data'=>json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
        ]);
        echo date('c')." {$flight['physical_flight']} actualizado\n";
    } catch (Throwable $e) { fwrite(STDERR, date('c')." {$flight['physical_flight']}: {$e->getMessage()}\n"); }
}

