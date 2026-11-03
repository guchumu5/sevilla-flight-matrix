<?php
declare(strict_types=1);

namespace SevillaMatrix\Sync;

use RuntimeException;

/**
 * Lectura directa del JSON público de Infovuelos (mismo backend que la web de Aena).
 * No requiere Playwright ni OAuth.
 */
final class AenaInfovuelosClient
{
    private const ENDPOINT = 'https://www.aena.es/sites/Satellite';
    private const STATUS_LABELS = [
        'SCH' => 'Programado',
        'INI' => 'Programado',
        'HOR' => 'En hora',
        'RET' => 'Retrasado',
        'EMB' => 'Embarcando',
        'ULL' => 'Última llamada',
        'NPT' => 'Cambio de puerta',
        'CER' => 'Puerta cerrada',
        'FLY' => 'En vuelo',
        'FNL' => 'En aproximación',
        'LND' => 'Aterrizado',
        'ATE' => 'Aterrizado',
        'OPE' => 'Entrega de equipaje',
        'OPF' => 'Entrega de equipaje',
        'IBK' => 'Entrega de equipaje',
        'BOR' => 'Finalizado',
        'CAN' => 'Cancelado',
        'DES' => 'Desviado',
    ];

    /** @return list<array<string,mixed>> */
    public function fetchArrivals(string $airport = 'SVQ'): array
    {
        $airport = strtoupper(trim($airport));
        if (!preg_match('/^[A-Z]{3}$/', $airport)) {
            throw new RuntimeException('Aeropuerto Aena no válido.');
        }
        $url = self::ENDPOINT
            . '?pagename=AENA_ConsultarVuelos'
            . '&airport=' . rawurlencode($airport)
            . '&flightType=L'
            . '&dosDias=si';
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json,text/plain,*/*',
                'Referer: https://www.aena.es/es/infovuelos.html',
                'User-Agent: SevillaFlightMatrix-AenaJson/2.0',
            ],
        ]);
        $body = (string)curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        if ($body === '' || $status < 200 || $status >= 300) {
            throw new RuntimeException(
                'Infovuelos JSON no respondió (' . ($status ?: 'sin HTTP') . ')'
                . ($error !== '' ? ': ' . $error : '')
            );
        }
        $decoded = json_decode($body, true);
        if (!is_array($decoded) || !array_is_list($decoded)) {
            throw new RuntimeException('Infovuelos JSON devolvió un formato inesperado.');
        }
        return $decoded;
    }

    public static function clean(mixed $value): ?string
    {
        if ($value === null) return null;
        $text = trim((string)$value);
        if ($text === '' || strcasecmp($text, 'null') === 0 || $text === '-') {
            return null;
        }
        return $text;
    }

    public static function statusLabel(?string $code): ?string
    {
        $code = strtoupper(trim((string)$code));
        if ($code === '') return null;
        return self::STATUS_LABELS[$code] ?? $code;
    }

    public static function spanishDateToIso(?string $fecha): ?string
    {
        $fecha = trim((string)$fecha);
        if (!preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $fecha, $m)) {
            return null;
        }
        return $m[3] . '-' . $m[2] . '-' . $m[1];
    }

    public static function clock(?string $hora): ?string
    {
        $hora = trim((string)$hora);
        if (!preg_match('/^(\d{2}):(\d{2})(?::\d{2})?$/', $hora, $m)) {
            return null;
        }
        return $m[1] . ':' . $m[2];
    }

    /** @return list<string> */
    public static function codesFromRow(array $row): array
    {
        $number = preg_replace('/\D+/', '', (string)($row['numVuelo'] ?? '')) ?? '';
        if ($number === '') return [];
        $codes = [];
        foreach ([
            self::clean($row['oaciCompania'] ?? null) ?: self::clean($row['compania'] ?? null),
            self::clean($row['iataCompania'] ?? null),
        ] as $prefix) {
            if ($prefix !== null && $prefix !== '') {
                $codes[] = strtoupper($prefix) . $number;
            }
        }
        foreach (['codigosCompania', 'iatasCompaniaSecundarios', 'oacisCompaniaSecundarios'] as $field) {
            $raw = self::clean($row[$field] ?? null);
            if ($raw === null) continue;
            foreach (explode(',', $raw) as $token) {
                $token = strtoupper(trim($token));
                if ($token === '' || $token === 'NULL') continue;
                if (preg_match('/^[A-Z0-9]{2,3}\d{1,4}[A-Z]?$/', $token)) {
                    $codes[] = $token;
                    continue;
                }
                if (preg_match('/^[A-Z]{2,3}$/', $token)) {
                    $codes[] = $token . $number;
                }
            }
        }
        $out = [];
        foreach ($codes as $code) {
            $code = strtoupper(preg_replace('/\s+/', '', $code) ?? '');
            if ($code !== '' && !in_array($code, $out, true)) {
                $out[] = $code;
            }
        }
        return $out;
    }
}
