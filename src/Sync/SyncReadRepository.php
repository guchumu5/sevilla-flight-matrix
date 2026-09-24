<?php
declare(strict_types=1);

namespace SevillaMatrix\Sync;

use PDO;

final class SyncReadRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<string,mixed> */
    public function dashboard(int $runLimit = 20, int $eventLimit = 100): array
    {
        $runLimit = max(1, min(100, $runLimit));
        $eventLimit = max(1, min(500, $eventLimit));

        $runs = $this->pdo->query(
            'SELECT id,provider,mode,window_from,window_to,started_at,finished_at,status,
             records_received,flights_created,flights_updated,observations_created,events_created,
             flights_missing,flights_withdrawn,error_message
             FROM sync_runs ORDER BY started_at DESC,id DESC LIMIT ' . $runLimit
        )->fetchAll();

        $events = $this->pdo->query(
            'SELECT e.id,e.sync_run_id,e.source,e.event_type,e.field_name,e.before_value,e.after_value,
             e.reason_code,e.reason_detail,e.confidence,e.detected_at,
             f.id AS flight_id,f.flight_date,f.physical_flight,f.origin_iata,f.origin_name,f.scheduled_arrival
             FROM flight_events e INNER JOIN flights f ON f.id=e.flight_id
             ORDER BY e.detected_at DESC,e.id DESC LIMIT ' . $eventLimit
        )->fetchAll();

        $fetchRuns = [];
        $fetchSummary = [
            'attempts' => 0,
            'successful_attempts' => 0,
            'failed_attempts' => 0,
            'records_received' => 0,
            'last_attempt_at' => null,
        ];
        if ($this->tableExists('fetch_runs')) {
            $fetchRuns = $this->pdo->query(
                'SELECT id,provider,started_at,finished_at,ok,records_count,error_message
                 FROM fetch_runs ORDER BY started_at DESC,id DESC LIMIT ' . $runLimit
            )->fetchAll();
            $fetchSummaryRow = $this->pdo->query(
                "SELECT COUNT(*) AS attempts,
                 SUM(ok=1) AS successful_attempts,
                 SUM(ok=0) AS failed_attempts,
                 SUM(records_count) AS records_received,
                 MAX(started_at) AS last_attempt_at
                 FROM fetch_runs"
            )->fetch() ?: [];
            $fetchSummary = [
                'attempts' => (int)($fetchSummaryRow['attempts'] ?? 0),
                'successful_attempts' => (int)($fetchSummaryRow['successful_attempts'] ?? 0),
                'failed_attempts' => (int)($fetchSummaryRow['failed_attempts'] ?? 0),
                'records_received' => (int)($fetchSummaryRow['records_received'] ?? 0),
                'last_attempt_at' => $fetchSummaryRow['last_attempt_at'] ?? null,
            ];
        }

        $summary = $this->pdo->query(
            "SELECT
             SUM(status='success') AS successful_runs,
             SUM(status='failed') AS failed_runs,
             MAX(CASE WHEN status='success' THEN finished_at END) AS last_success_at,
             MAX(CASE WHEN status='failed' THEN finished_at END) AS last_failure_at
             FROM sync_runs"
        )->fetch() ?: [];
        $eventSummary = $this->pdo->query(
            "SELECT COUNT(*) AS events_today,
             SUM(event_type IN ('belt_assigned','belt_changed','belt_removed')) AS belt_events_today,
             SUM(event_type IN ('flight_missing','flight_withdrawn')) AS visibility_events_today
             FROM flight_events WHERE detected_at>=CURRENT_DATE()"
        )->fetch() ?: [];

        return [
            'summary' => [
                'successful_runs' => (int)($summary['successful_runs'] ?? 0),
                'failed_runs' => (int)($summary['failed_runs'] ?? 0),
                'last_success_at' => $summary['last_success_at'] ?? null,
                'last_failure_at' => $summary['last_failure_at'] ?? null,
                'events_today' => (int)($eventSummary['events_today'] ?? 0),
                'belt_events_today' => (int)($eventSummary['belt_events_today'] ?? 0),
                'visibility_events_today' => (int)($eventSummary['visibility_events_today'] ?? 0),
            ],
            'collector_summary' => $fetchSummary,
            'fetch_runs' => $fetchRuns,
            'runs' => $runs,
            'events' => $events,
        ];
    }

    private function tableExists(string $table): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=:table_name'
        );
        $stmt->execute(['table_name' => $table]);
        return (int)$stmt->fetchColumn() > 0;
    }
}
