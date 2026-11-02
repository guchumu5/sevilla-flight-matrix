<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use SevillaMatrix\BeltAlarm;
use SevillaMatrix\Env;
use SevillaMatrix\FlightRepository;
use SevillaMatrix\Notifications\CriticalBeltEvents;
use SevillaMatrix\Notifications\WebPushService;
use SevillaMatrix\Sync\FlightReconciliationService;

// A disposable database and injected transport: never contact real passengers.
if (Env::get('APP_ENV') !== 'testing') throw new RuntimeException('APP_ENV=testing is required.');
$host = Env::get('DB_HOST', '127.0.0.1');
$port = Env::get('DB_PORT', '3306');
$pdo = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", Env::get('DB_USER', 'root'), Env::get('DB_PASS', ''), [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$name = 'svq_belt_test_' . bin2hex(random_bytes(6));
$cache = sys_get_temp_dir() . '/' . $name;
mkdir($cache);
$pdo->exec("CREATE DATABASE {$name} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
try {
    $pdo->exec("USE {$name}");
    $pdo->exec((string)file_get_contents(PROJECT_ROOT . '/database/schema.sql'));
    $pdo->exec('SET timestamp=' . strtotime('2026-11-02 12:00:00 UTC'));
    $clock = (string)$pdo->query('SELECT NOW()')->fetchColumn();
    $tick = strtotime($clock) - 100;
    $log = []; $fail = []; $encrypted = true; $nested = null; $httpStatus = [];
    $transport = static function (array $subscription, array $notice) use (&$log, &$fail, &$encrypted, &$nested, &$httpStatus): array {
        $id = (int)$subscription['id'];
        if (($fail[$id] ?? 0) > 0) { $fail[$id]--; throw new RuntimeException('Controlled transport failure'); }
        if (isset($httpStatus[$id])) return ['status' => $httpStatus[$id], 'encrypted' => true];
        $log[] = ['subscription_id' => $id, 'notice' => $notice];
        if ($nested !== null) { $call = $nested; $nested = null; $call(); }
        return ['status' => 201, 'encrypted' => $encrypted];
    };
    $svc = new WebPushService($pdo, null, $transport, $cache);
    $tokens = [];
    for ($i = 1; $i <= 4; $i++) {
        $token = str_repeat((string)$i, 64); $tokens[$i] = $token;
        $stmt = $pdo->prepare('INSERT INTO push_subscriptions (device_token,endpoint,endpoint_hash,p256dh,auth_secret,active,watch_canary_all,created_at,updated_at) VALUES (?,?,?,?,?,?,?,DATE_SUB(NOW(),INTERVAL 1 HOUR),DATE_SUB(NOW(),INTERVAL 1 HOUR))');
        $endpoint = 'https://push.example.invalid/' . $i;
        $stmt->execute([$token, $endpoint, hash('sha256', $endpoint), 'fake', 'fake', $i === 4 ? 0 : 1, $i === 2 ? 1 : 0]);
    }
    $sync = new FlightReconciliationService($pdo);
    $sequence = 9000;
    $make = static function (string $belt = '3', string $hall = 'A', string $source = 'aena') use ($pdo, $sync, &$sequence, &$tick, $clock): array {
        $flight = [
            'flight_date' => substr($clock, 0, 10), 'physical_flight' => 'VLG' . ++$sequence,
            'origin_iata' => 'MAD', 'origin_name' => 'Controlled fixture',
            'scheduled_arrival' => date('Y-m-d H:i:s', strtotime($clock) - 7200), 'belt' => $belt, 'hall' => $hall,
            'status' => 'Programado', 'is_canary' => 0,
        ];
        $sync->sync([$flight], ['provider' => $source, 'observed_at' => date('Y-m-d H:i:s', $tick++)]);
        $id = (int)$pdo->query('SELECT MAX(id) FROM flights')->fetchColumn();
        $pdo->exec('DELETE FROM flight_events WHERE flight_id=' . $id);
        $pdo->exec("INSERT INTO flight_watches (subscription_id,flight_id,enabled,updated_at) VALUES (1,{$id},1,DATE_SUB(NOW(),INTERVAL 1 HOUR))");
        return [$id, $flight];
    };
    $change = static function (array &$flight, string $belt, string $hall = 'A', string $source = 'aena') use ($sync, &$tick): void {
        $flight['belt'] = $belt; $flight['hall'] = $hall;
        $sync->sync([$flight], ['provider' => $source, 'observed_at' => date('Y-m-d H:i:s', $tick++)]);
    };
    $criticalLog = static function () use (&$log): array { return array_values(array_filter($log, static fn(array $entry): bool => !empty($entry['notice']['urgent']))); };

    foreach (json_decode((string)file_get_contents(__DIR__ . '/fixtures/belt-alarm-cases.json'), true) as [$from, $to, $expected]) {
        check(BeltAlarm::isBlockChange($from, $to) === $expected, 'Block policy mismatch: ' . $from . ' → ' . $to);
    }
    [$id, $flight] = $make();
    $change($flight, '4'); $svc->dispatch();
    check(count($criticalLog()) === 0, 'Same-block change became critical');
    check(count($log) === 1 && $log[0]['subscription_id'] === 1, 'Ordinary change escaped its watched audience: ' . json_encode($log));
    $log = [];
    $change($flight, '7', 'B');
    $first = $svc->dispatch(1);
    check($first['queued'] === 2, 'Dispatch limit did not retain both unwatched recipients');
    $svc->dispatch(); $svc->dispatch();
    check(count($criticalLog()) === 3, 'Critical broadcast duplicated or lost a recipient');
    check(array_column($criticalLog(), 'subscription_id') === [1,2,3], 'Active global audience changed');
    $eventId = $criticalLog()[0]['notice']['critical_belt']['id'];
    $board = (new FlightRepository($pdo))->board(substr($clock, 0, 10));
    $card = array_values(array_filter($board, static fn(array $row): bool => (int)$row['id'] === $id))[0];
    check($card['critical_belt_event'] === $criticalLog()[0]['notice']['critical_belt'], 'Visual and push event payloads disagree');
    check($svc->pending($tokens[1]) === null, 'Encrypted push replayed through pending');
    check((int)$pdo->query("SELECT COUNT(*) FROM push_outbox o JOIN flight_events e ON e.id=o.flight_event_id WHERE e.event_type='hall_changed' AND o.pushed_at IS NOT NULL")->fetchColumn() === 0, 'Paired hall event sent a duplicate notice');
    $change($flight, '7', 'B'); $svc->dispatch();
    check(count($criticalLog()) === 3, 'Repeated official snapshot resent the alarm');
    // A repeated equivalent event from a second ingest run must retain identity.
    $pdo->exec("INSERT INTO flight_events (flight_id,source,event_type,field_name,before_value,after_value,reason_code,reason_detail,confidence,detected_at,event_fingerprint) SELECT flight_id,source,event_type,field_name,before_value,after_value,reason_code,reason_detail,confidence,detected_at,SHA2(CONCAT(event_fingerprint,'duplicate'),256) FROM flight_events WHERE id=" . (int)$eventId);
    $svc->dispatch();
    check(count($criticalLog()) === 3, 'Equivalent event with a new row ID resent the alarm');
    check((string)(new CriticalBeltEvents($pdo))->current()[$id]['id'] === $eventId, 'Repeated event changed canonical identity');
    $change($flight, '3'); $svc->dispatch();
    $reverseId = $criticalLog()[3]['notice']['critical_belt']['id'];
    $change($flight, '7', 'B'); $svc->dispatch();
    check(count($criticalLog()) === 9, 'Legitimate fast alternations were lost to deduplication/cooldown');
    check(count(array_unique([$eventId,$reverseId,$criticalLog()[6]['notice']['critical_belt']['id']])) === 3, 'Alternations reused event identity');

    [$retryId, $retry] = $make(); $change($retry, '7', 'B');
    $fail[3] = 1; $start = count($criticalLog());
    $result = $svc->dispatch(); check($result['failed'] === 1 && $result['queued'] === 1, 'Failure did not remain retryable');
    $svc->dispatch(); check(count($criticalLog()) === $start + 3, 'Unwatched retry was discarded or duplicated');
    $svc->dispatch(); check(count($criticalLog()) === $start + 3, 'Successful retry resent');

    [$obsoleteId, $obsolete] = $make(); $change($obsolete, '7', 'B'); $fail[3] = 1;
    $svc->dispatch(); $start = count($criticalLog()); $change($obsolete, '3'); $svc->dispatch();
    check(count($criticalLog()) === $start + 3, 'Superseded forward alarm was retried');
    check(array_column(array_slice($criticalLog(), $start), 'notice')[0]['critical_belt']['to'] === 'A/3', 'Queue retained an obsolete destination');

    [$batchId, $batch] = $make(); $change($batch, '7', 'B'); $change($batch, '3'); $change($batch, '7', 'B');
    $start = count($criticalLog()); $svc->dispatch();
    check(count($criticalLog()) === $start + 3, 'Backlog replayed obsolete alternating assignments');

    [$fallbackId, $fallback] = $make(); $change($fallback, '7', 'B'); $encrypted = false;
    $svc->dispatch(); $encrypted = true;
    $pending = $svc->pending($tokens[3]);
    check($pending !== null && $pending['critical_belt']['flight_id'] === (string)$fallbackId, 'Pending lost critical payload');
    check($pending['body'] === BeltAlarm::pushBody('3', '7') && $pending['urgent'], 'Fallback alarm text/urgency differs');
    check($svc->pending($tokens[3]) === null, 'Pending claimed the same event twice');
    $svc->pending($tokens[1]); $svc->pending($tokens[2]);

    [$concurrentId, $concurrent] = $make(); $change($concurrent, '7', 'B');
    $start = count($criticalLog()); $nested = static fn() => $svc->dispatch(); $svc->dispatch();
    check(count($criticalLog()) === $start + 3, 'Overlapping dispatchers duplicated a reservation');

    // Synchronization/deactivation during a dispatch cannot send selected stale rows.
    [$liveId, $live] = $make(); $change($live, '7', 'B'); $start = count($criticalLog());
    $nested = static function () use (&$live, $change): void { $change($live, '3'); };
    $svc->dispatch();
    check(count($criticalLog()) === $start + 1, 'A stale assignment escaped after synchronization');
    $svc->dispatch(); check(count($criticalLog()) === $start + 4, 'The replacement assignment was not broadcast');

    [$terminalId, $terminal] = $make(); $change($terminal, '7', 'B'); $fail[3] = 10;
    for ($i=0; $i<5; $i++) $result = $svc->dispatch();
    check($result['queued'] === 0, 'Exhausted attempts counted as pending');
    check((int)$pdo->query("SELECT MAX(attempts) FROM push_outbox o JOIN flight_events e ON e.id=o.flight_event_id WHERE e.flight_id={$terminalId} AND o.subscription_id=3")->fetchColumn() === 4, 'Retry budget exceeded');
    $fail[3] = 0;

    $start = count($criticalLog());
    [$redId, $red] = $make('7', 'B'); $change($red, '8', 'B'); $svc->dispatch();
    check(count($criticalLog()) === $start, '7 → 8 raised a critical broadcast');
    [$assignmentId, $assignment] = $make('', ''); $change($assignment, '7', 'B'); $svc->dispatch();
    check(count($criticalLog()) === $start, 'First official assignment raised a critical broadcast');
    [$removedId, $removed] = $make(); $change($removed, '7', 'B'); $change($removed, '', ''); $svc->dispatch();
    check(count($criticalLog()) === $start, 'Removed assignment retained a stale alarm');

    [$hallId, $hall] = $make(); $change($hall, '3', 'B'); $start = count($criticalLog()); $svc->dispatch();
    check(count($criticalLog()) === $start, 'Hall-only change raised a crisis');
    [$secondaryId, $secondary] = $make('3', 'A', 'airlabs'); $change($secondary, '7', 'B', 'airlabs'); $svc->dispatch();
    check(count($criticalLog()) === $start, 'Secondary source raised a crisis');
    [$oldId, $old] = $make(); $change($old, '7', 'B');
    $pdo->exec("UPDATE flight_events SET detected_at=DATE_SUB(NOW(),INTERVAL 21 MINUTE) WHERE flight_id={$oldId}");
    $svc->dispatch(); check(count($criticalLog()) === $start, 'Expired event raised a crisis');
    [$futureId, $future] = $make(); $change($future, '7', 'B');
    $pdo->exec("UPDATE flight_events SET detected_at=DATE_ADD(NOW(),INTERVAL 1 MINUTE) WHERE flight_id={$futureId}");
    $svc->dispatch(); check(count($criticalLog()) === $start, 'Future-dated event raised a crisis');
    // Latest assignment/removal/within-block move invalidates the old alarm.
    [$supId, $sup] = $make(); $change($sup, '7', 'B'); $change($sup, '8', 'B');
    $svc->dispatch(); check(count($criticalLog()) === $start, 'Superseded crisis survived an ordinary move');
    // More than 30 ordinary events must not starve a critical flight.
    [$deepId, $deep] = $make(); $change($deep, '7', 'B');
    for ($i=0; $i<35; $i++) { [$noiseId,$noise]=$make(); $change($noise,'4'); }
    // Clock advances above now only in the test generator; normalize to a recent second.
    $pdo->exec("UPDATE flight_events SET detected_at=DATE_SUB(NOW(),INTERVAL 1 SECOND) WHERE flight_id>={$deepId}");
    $svc->dispatch(200);
    check(count($criticalLog()) === $start + 3, 'Ordinary events starved a legitimate critical alarm');
    $tick = strtotime($clock) - 5;
    [$goneId, $gone] = $make(); $change($gone, '7', 'B'); $httpStatus[3] = 410;
    $svc->dispatch();
    check((int)$pdo->query('SELECT active FROM push_subscriptions WHERE id=3')->fetchColumn() === 0, 'HTTP 410 did not deactivate the expired device');
    unset($httpStatus[3]); $start = count($criticalLog()); $svc->dispatch();
    check(count($criticalLog()) === $start, 'Expired device was retried');
    $pdo->exec('UPDATE push_subscriptions SET active=1 WHERE id=3');
    [$disabledId, $disabled] = $make(); $change($disabled, '7', 'B'); $start = count($criticalLog());
    $nested = static function () use ($pdo): void { $pdo->exec('UPDATE push_subscriptions SET active=0 WHERE id=3'); };
    $svc->dispatch();
    check(count($criticalLog()) === $start + 2, 'Device deactivated during dispatch still received the alarm');

    fwrite(STDOUT, "Critical belt circuit (isolated MySQL, fake transport): OK\n");
} finally {
    $pdo->exec("DROP DATABASE {$name}");
    foreach (glob($cache . '/*') ?: [] as $path) if (is_file($path)) unlink($path);
    rmdir($cache);
}
