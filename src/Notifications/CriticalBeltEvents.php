<?php
declare(strict_types=1);

namespace SevillaMatrix\Notifications;

use PDO;
use SevillaMatrix\BeltAlarm;

final class CriticalBeltEvents
{
    public function __construct(private readonly PDO $pdo) {}

    public function current(): array
    {
        // No LIMIT: ordinary belt changes must not hide a legitimate crisis.
        // Include earlier history to retain the identity of repeated events.
        $events = $this->pdo->query(
            "SELECT e.*,f.physical_flight,f.origin_name,f.flight_date,f.is_canary,
                    (e.detected_at BETWEEN DATE_SUB(NOW(),INTERVAL 20 MINUTE) AND NOW()) AS recent,
                    UNIX_TIMESTAMP(e.detected_at)+1200 AS expires_at
             FROM flight_events e JOIN flights f ON f.id=e.flight_id
             WHERE e.source='aena' AND e.event_type IN ('belt_assigned','belt_changed','belt_removed')
               AND e.detected_at<=NOW()
               AND e.flight_id IN (
                 SELECT flight_id FROM flight_events
                 WHERE source='aena' AND event_type IN ('belt_assigned','belt_changed','belt_removed')
                   AND detected_at BETWEEN DATE_SUB(NOW(),INTERVAL 20 MINUTE) AND NOW()
               )
             ORDER BY e.detected_at,e.id"
        )->fetchAll();
        return BeltAlarm::currentEvents($events);
    }
}
