<?php
declare(strict_types=1);

namespace SevillaMatrix\Sync;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

/**
 * Evidencias Infovuelos (Aena). Nunca gasta cuota AeroAPI.
 * Guarda snapshots JSON con timestamp para análisis manual de cinta.
 */
final class AenaEvidenceService
{
    private const CANARY = ['LPA', 'TFN', 'TFS', 'ACE', 'FUE'];
    private const DIR = 'storage/evidence';

    private readonly string $root;

    public function __construct(?string $root = null)
    {
        $this->root = $root ?? dirname(__DIR__, 2);
    }

    /** @return array{ok:bool,path:string,meta:array<string,mixed>,canarias:list<array<string,mixed>>,quota_note:string} */
    public function captureLive(string $airport = 'SVQ', string $by = 'ui'): array
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('Europe/Madrid'));
        $client = new AenaInfovuelosClient();
        $rows = $client->fetchArrivals($airport);
        $canarias = $this->filterCanarias($rows);
        $stamp = $now->format('Y-m-d_H-i-s');
        $dir = $this->evidenceDir();
        $basename = 'aena-' . strtolower($airport) . '-live-' . $stamp;
        $jsonPath = $dir . '/' . $basename . '.json';
        $meta = [
            'captured_at' => $now->format('Y-m-d H:i:s'),
            'timezone' => 'Europe/Madrid',
            'airport' => strtoupper($airport),
            'source' => 'AENA_ConsultarVuelos',
            'flight_type' => 'L',
            'requested_by' => $by,
            'raw_rows' => count($rows),
            'canarias_rows' => count($canarias),
            'aeroapi_spent' => 0,
            'note' => 'Evidencia Infovuelos pura: no consume cuota AeroAPI.',
        ];
        $payload = [
            'meta' => $meta,
            'canarias' => $canarias,
            'rows' => $rows,
        ];
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($encoded === false || file_put_contents($jsonPath, $encoded) === false) {
            throw new RuntimeException('No se pudo guardar la evidencia Infovuelos.');
        }

        $comparePath = $dir . '/' . $basename . '-cintas.json';
        $sideBySide = [
            'meta' => $meta + [
                'title' => 'Aena Infovuelos vs FlightAware (baggage pendiente de cuota)',
                'warning' => 'Esta captura es solo Aena. Para rellenar FlightAware hace falta cuota AeroAPI aparte.',
            ],
            'flights' => array_map(static function (array $row): array {
                return [
                    'code' => strtoupper(trim((string)($row['compania'] ?? '')) . trim((string)($row['numVuelo'] ?? ''))),
                    'origin' => strtoupper((string)($row['iataOtro'] ?? '')),
                    'origin_name' => (string)($row['ciudadIataOtro'] ?? ''),
                    'scheduled' => (string)($row['horaProgramada'] ?? ''),
                    'estimated' => (string)($row['horaEstimada'] ?? ''),
                    'date' => (string)($row['fecha'] ?? ''),
                    'hall_aena' => AenaInfovuelosClient::clean($row['salaPrimera'] ?? null),
                    'belt_aena' => AenaInfovuelosClient::clean($row['cintaPrimera'] ?? null),
                    'belt_aeroapi' => null,
                    'status_aena' => (string)($row['estado'] ?? ''),
                    'agreement' => 'aena_only',
                ];
            }, $canarias),
        ];
        file_put_contents(
            $comparePath,
            json_encode($sideBySide, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: '{}'
        );

        return [
            'ok' => true,
            'path' => $this->publicRelative($jsonPath),
            'compare_path' => $this->publicRelative($comparePath),
            'meta' => $meta,
            'canarias' => $canarias,
            'quota_note' => '0 result sets AeroAPI (solo Infovuelos JSON).',
            'message' => sprintf(
                'Evidencia Aena guardada %s · %d Canarias · sin gastar AeroAPI.',
                $meta['captured_at'],
                count($canarias)
            ),
        ];
    }

    /**
     * Lista evidencias (más recientes primero).
     *
     * @return list<array<string,mixed>>
     */
    public function listEvidence(int $limit = 40): array
    {
        $dir = $this->evidenceDir();
        $files = glob($dir . '/aena-*.json') ?: [];
        rsort($files, SORT_STRING);
        $out = [];
        foreach ($files as $file) {
            if (str_ends_with($file, '-cintas.json') && !str_contains(basename($file), '-live-')) {
                // Las companion de comparación se listan junto a su captura live.
            }
            $base = basename($file);
            if (str_contains($base, '-cintas.json') && str_contains($base, '-live-')) {
                continue; // se enlazan desde el meta de la captura live
            }
            $mtime = filemtime($file) ?: time();
            $meta = null;
            $raw = @file_get_contents($file);
            if (is_string($raw) && str_starts_with(ltrim($raw), '{')) {
                $decoded = json_decode($raw, true);
                if (is_array($decoded) && isset($decoded['meta']) && is_array($decoded['meta'])) {
                    $meta = $decoded['meta'];
                }
            }
            $captured = (string)($meta['captured_at'] ?? date('Y-m-d H:i:s', $mtime));
            $compare = null;
            $liveCompare = preg_replace('/\.json$/', '-cintas.json', $file);
            if (is_string($liveCompare) && is_file($liveCompare)) {
                $compare = $this->publicRelative($liveCompare);
            } elseif ($base === 'aena-svq-canarias-2026-11-03.json') {
                $jpg = $dir . '/aena-svq-canarias-cintas.jpg';
                if (is_file($jpg)) $compare = $this->publicRelative($jpg);
            }
            $image = null;
            if (!empty($meta['image'])) {
                $image = (string)$meta['image'];
            } elseif ($base === 'aena-svq-canarias-2026-11-03.json') {
                $image = $this->publicRelative($dir . '/aena-svq-canarias-cintas.jpg');
            } elseif ($base === 'ground-truth-vlg3190-cinta7-2026-11-02.json'
                || str_contains($base, 'dual-')) {
                $gt = $dir . '/aena-infovuelos-vlg3190-cinta7-2026-11-02-2233.png';
                if (is_file($gt)) $image = $this->publicRelative($gt);
            }
            $html = preg_replace('/\.json$/', '.html', $file);
            if (is_string($html) && is_file($html) && $compare === null) {
                $compare = $this->publicRelative($html);
            }
            $out[] = [
                'id' => $base,
                'path' => $this->publicRelative($file),
                'compare_path' => $compare,
                'captured_at' => $captured,
                'size_bytes' => filesize($file) ?: 0,
                'canarias_rows' => (int)($meta['canarias_rows'] ?? $meta['raw_rows'] ?? 0),
                'aeroapi_spent' => (int)($meta['aeroapi_spent'] ?? 0),
                'note' => (string)($meta['note'] ?? $meta['reason'] ?? 'Evidencia Aena Infovuelos'),
                'image' => $image,
                'superseded' => !empty($meta['superseded']),
            ];
            if (count($out) >= $limit) break;
        }
        // Ground truth del bug cinta 7 (si no entró por glob aena-*).
        $gtJson = $dir . '/ground-truth-vlg3190-cinta7-2026-11-02.json';
        if (is_file($gtJson) && count($out) < $limit) {
            array_unshift($out, [
                'id' => 'ground-truth-vlg3190-cinta7-2026-11-02.json',
                'path' => $this->publicRelative($gtJson),
                'compare_path' => null,
                'captured_at' => '2026-11-02 22:33:00',
                'size_bytes' => filesize($gtJson) ?: 0,
                'canarias_rows' => 1,
                'aeroapi_spent' => 0,
                'note' => 'GROUND TRUTH: VLG3190 Entrega Equip. · Cinta 7 (app Infovuelos).',
                'image' => $this->publicRelative($dir . '/aena-infovuelos-vlg3190-cinta7-2026-11-02-2233.png'),
                'superseded' => false,
            ]);
        }
        return $out;
    }

    /**
     * Regenera evidencia lado a lado Aena (vivo) + baggage AeroAPI ya conocido en BD
     * (sin llamar a AeroAPI: no gasta cuota). Si se pasa un mapa flightId→belt, lo usa.
     *
     * @param list<array{code:string,belt_aeroapi:?string}>|null $aeroHints
     * @return array<string,mixed>
     */
    public function regenerateDualCompare(?array $aeroHints = null, string $by = 'regen'): array
    {
        $live = $this->captureLive('SVQ', $by);
        $hints = [];
        foreach ($aeroHints ?? [] as $hint) {
            $code = strtoupper(preg_replace('/\s+/', '', (string)($hint['code'] ?? '')) ?? '');
            if ($code === '') continue;
            $hints[$code] = AenaInfovuelosClient::clean($hint['belt_aeroapi'] ?? null);
        }
        $compareFile = $this->root . '/' . ltrim((string)$live['compare_path'], '/');
        $decoded = is_file($compareFile) ? json_decode((string)file_get_contents($compareFile), true) : null;
        if (!is_array($decoded)) {
            return $live;
        }
        $flights = [];
        foreach ((array)($decoded['flights'] ?? []) as $row) {
            if (!is_array($row)) continue;
            $code = strtoupper((string)($row['code'] ?? ''));
            $aero = $hints[$code] ?? null;
            $aena = AenaInfovuelosClient::clean($row['belt_aena'] ?? null);
            $agreement = 'none';
            if ($aena !== null && $aero !== null) {
                $agreement = $aena === $aero ? 'confirmed' : 'diverge';
            } elseif ($aena !== null) {
                $agreement = 'aena_only';
            } elseif ($aero !== null) {
                $agreement = 'aeroapi_only';
            }
            $row['belt_aeroapi'] = $aero;
            $row['agreement'] = $agreement;
            $flights[] = $row;
        }
        $decoded['flights'] = $flights;
        $decoded['meta']['title'] = 'Aena Infovuelos vs FlightAware baggage (sin gastar cuota en esta captura)';
        $decoded['meta']['aeroapi_spent'] = 0;
        $decoded['meta']['dual'] = true;
        file_put_contents(
            $compareFile,
            json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: '{}'
        );

        $htmlPath = preg_replace('/\.json$/', '.html', $compareFile) ?: ($compareFile . '.html');
        file_put_contents($htmlPath, $this->renderCompareHtml($decoded));

        $live['compare_path'] = $this->publicRelative($compareFile);
        $live['html_path'] = $this->publicRelative($htmlPath);
        $live['flights'] = $flights;
        $live['message'] = sprintf(
            'Evidencia dual Aena vs FA (hints BD) · %d vuelos · 0 AeroAPI.',
            count($flights)
        );
        return $live;
    }

    /** @param array<string,mixed> $payload */
    private function renderCompareHtml(array $payload): string
    {
        $meta = is_array($payload['meta'] ?? null) ? $payload['meta'] : [];
        $rows = '';
        foreach ((array)($payload['flights'] ?? []) as $f) {
            if (!is_array($f)) continue;
            $agr = (string)($f['agreement'] ?? '');
            $cls = $agr === 'diverge' ? 'diverge' : ($agr === 'confirmed' ? 'ok' : '');
            $rows .= '<tr class="' . htmlspecialchars($cls) . '">'
                . '<td>' . htmlspecialchars((string)($f['code'] ?? '')) . '</td>'
                . '<td>' . htmlspecialchars((string)($f['origin'] ?? '')) . '</td>'
                . '<td>' . htmlspecialchars((string)($f['scheduled'] ?? '')) . '</td>'
                . '<td>' . htmlspecialchars((string)($f['hall_aena'] ?? '—')) . '</td>'
                . '<td><strong>' . htmlspecialchars((string)($f['belt_aena'] ?? '—')) . '</strong></td>'
                . '<td><strong>' . htmlspecialchars((string)($f['belt_aeroapi'] ?? '—')) . '</strong></td>'
                . '<td>' . htmlspecialchars($agr) . '</td>'
                . '<td>' . htmlspecialchars((string)($f['status_aena'] ?? '')) . '</td>'
                . '</tr>';
        }
        $title = htmlspecialchars((string)($meta['title'] ?? 'Evidencia cintas'));
        $when = htmlspecialchars((string)($meta['captured_at'] ?? ''));
        return '<!doctype html><html lang="es"><head><meta charset="utf-8"><title>' . $title . '</title>'
            . '<style>body{font:14px/1.4 system-ui,sans-serif;background:#07111f;color:#eef6ff;padding:1.5rem}'
            . 'h1{font-size:1.2rem}table{border-collapse:collapse;width:100%;margin-top:1rem}'
            . 'th,td{border:1px solid #233852;padding:.45rem .55rem;text-align:left}'
            . 'th{background:#12243a}.ok td{background:rgba(47,211,138,.12)}'
            . '.diverge td{background:rgba(255,95,109,.18)}.note{color:#8fa5bd;font-size:.85rem}</style></head><body>'
            . '<h1>' . $title . '</h1>'
            . '<p class="note">Captura ' . $when . ' · Europe/Madrid · AeroAPI gastado en esta evidencia: 0'
            . ' · Fuente Aena: Satellite AENA_ConsultarVuelos</p>'
            . '<table><thead><tr><th>Código</th><th>Origen</th><th>Hora</th><th>Sala Aena</th>'
            . '<th>Cinta Aena</th><th>Cinta AeroAPI</th><th>Acuerdo</th><th>Estado</th></tr></thead>'
            . '<tbody>' . $rows . '</tbody></table>'
            . '<p class="note">Si divergen → alerta y análisis manual. Si solo una tiene cinta → etiqueta de fuente.</p>'
            . '</body></html>';
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function filterCanarias(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $origin = strtoupper((string)(AenaInfovuelosClient::clean($row['iataOtro'] ?? null) ?? ''));
            if (!in_array($origin, self::CANARY, true)) continue;
            $out[] = $row;
        }
        return $out;
    }

    private function evidenceDir(): string
    {
        $dir = ($this->root ?? dirname(__DIR__, 2)) . '/' . self::DIR;
        if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new RuntimeException('No se pudo crear storage/evidence.');
        }
        return $dir;
    }

    private function publicRelative(string $absolute): string
    {
        $root = ($this->root ?? dirname(__DIR__, 2)) . '/';
        if (str_starts_with($absolute, $root)) {
            return substr($absolute, strlen($root));
        }
        return $absolute;
    }
}
