<?php
declare(strict_types=1);

namespace SevillaMatrix\Sync;

use PDO;
use RuntimeException;
use SevillaMatrix\Env;
use SevillaMatrix\IataFlightCode;

/**
 * Cliente HTTP de FlightAware AeroAPI (API de pago).
 *
 * Autenticación: cabecera x-apikey.
 * Claves admitidas (primera no vacía): FLIGHTAWARE_API_KEY, AEROAPI_KEY,
 * FLIGHTAWARE_AEROAPI_KEY.
 *
 * Solo consultas por ident concreto (nunca listados de aeropuerto).
 * Cada respuesta consume ceil(vuelos/15) result sets (o num_pages).
 */
final class AeroApiClient
{
    private const BASE = 'https://aeroapi.flightaware.com/aeroapi';
    private const USER_AGENT = 'SevillaFlightMatrix/1.27 AeroAPI';

    private readonly AeroApiQuotaService $quota;

    public function __construct(?PDO $pdo = null, ?AeroApiQuotaService $quota = null)
    {
        if ($quota !== null) {
            $this->quota = $quota;
        } elseif ($pdo !== null) {
            $this->quota = new AeroApiQuotaService($pdo);
        } else {
            throw new RuntimeException('AeroApiClient necesita PDO o AeroApiQuotaService para controlar la cuota.');
        }
    }

    public function configured(): bool
    {
        return $this->apiKey() !== '';
    }

    public function apiKey(): string
    {
        foreach (['FLIGHTAWARE_API_KEY', 'AEROAPI_KEY', 'FLIGHTAWARE_AEROAPI_KEY'] as $name) {
            $value = trim((string)Env::get($name, ''));
            if ($value !== '') return $value;
        }
        return '';
    }

    public function quota(): AeroApiQuotaService
    {
        return $this->quota;
    }

    /**
     * @param array<string,mixed> $flight  Debe incluir iata_carrier/iata_number o physical_flight
     * @return array<string,mixed>
     */
    public function fetchFlight(array $flight): array
    {
        if (!$this->configured()) {
            throw new RuntimeException('AeroAPI no está configurada: falta FLIGHTAWARE_API_KEY (o AEROAPI_KEY) en .env.');
        }
        $this->quota->assertCanSpend(1);

        $idents = IataFlightCode::aeroIdents(
            (string)($flight['physical_flight'] ?? ''),
            preg_split('/\s*,\s*/', (string)($flight['aliases'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: []
        );
        if ($idents === null) {
            $carrier = strtoupper(trim((string)($flight['iata_carrier'] ?? '')));
            $number = trim((string)($flight['iata_number'] ?? ''));
            if ($carrier !== '' && $number !== '') {
                $idents = IataFlightCode::aeroIdents($carrier . $number);
            }
        }
        if ($idents === null) {
            throw new RuntimeException('No se pudo traducir el vuelo a un ident AeroAPI (IATA/ICAO).');
        }

        $scheduled = (string)($flight['scheduled_arrival'] ?? 'now');
        // Ventana estrecha (±12 h) para no arrastrar páginas innecesarias.
        $start = (new \DateTimeImmutable($scheduled))->modify('-12 hours')->setTimezone(new \DateTimeZone('UTC'));
        $end = (new \DateTimeImmutable($scheduled))->modify('+12 hours')->setTimezone(new \DateTimeZone('UTC'));
        $query = [
            'ident_type' => 'designator',
            'start' => $start->format('Y-m-d\TH:i:s\Z'),
            'end' => $end->format('Y-m-d\TH:i:s\Z'),
            'max_pages' => 1,
        ];

        $errors = [];
        $spent = 0;
        foreach (array_values(array_unique(array_filter([
            $idents['icao'] ?? null,
            $idents['iata'] ?? null,
        ]))) as $ident) {
            if (!$this->quota->canSpend(1)) {
                $errors[] = $ident . ': cuota agotada tras ' . $spent . ' result set(s)';
                break;
            }
            try {
                $payload = $this->get('/flights/' . rawurlencode($ident), $query);
                $sets = AeroApiQuotaService::estimateResultSets($payload);
                $this->quota->record($sets, 1);
                $spent += $sets;

                $rows = AeroApiParser::flightsFromResponse($payload);
                $picked = AeroApiParser::pickLeg(
                    $rows,
                    (string)($flight['origin_iata'] ?? ''),
                    $scheduled,
                    (string)($flight['destination_iata'] ?? 'SVQ')
                );
                if ($picked === null) {
                    $errors[] = $ident . ': sin tramo coincidente en la ventana';
                    continue;
                }
                $normalized = AeroApiParser::normalize($picked, $scheduled);
                $normalized['source_kind'] = 'aeroapi';
                $normalized['queried_ident'] = $ident;
                $normalized['iata_carrier'] = $idents['carrier'];
                $normalized['iata_number'] = $idents['number'];
                $normalized['result_sets_spent'] = $spent;
                $normalized['quota'] = $this->quota->status();
                $normalized['raw_excerpt'] = json_encode([
                    'queried_ident' => $ident,
                    'fa_flight_id' => $normalized['fa_flight_id'],
                    'status' => $normalized['status_text'],
                    'delay_minutes' => $normalized['delay_minutes'],
                    'belt' => $normalized['belt'],
                    'gate' => $normalized['gate'],
                    'progress_percent' => $normalized['progress_percent'],
                    'result_sets_spent' => $spent,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                return $normalized;
            } catch (RuntimeException $error) {
                $errors[] = $ident . ': ' . $error->getMessage();
            }
        }

        throw new RuntimeException(
            $errors !== []
                ? 'AeroAPI no devolvió el tramo: ' . implode(' · ', $errors)
                : 'AeroAPI no devolvió el tramo solicitado.'
        );
    }

    /**
     * @param array<string,scalar|null> $query
     * @return array<string,mixed>
     */
    private function get(string $path, array $query = []): array
    {
        $url = self::BASE . $path;
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }
        $curl = curl_init($url);
        if ($curl === false) {
            throw new RuntimeException('No se pudo iniciar cURL hacia AeroAPI.');
        }
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'x-apikey: ' . $this->apiKey(),
            ],
        ]);
        $body = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $errno = curl_errno($curl);
        $error = curl_error($curl);
        curl_close($curl);

        if ($errno !== 0) {
            throw new RuntimeException('Error de red AeroAPI: ' . $error);
        }
        if (!is_string($body) || $body === '') {
            throw new RuntimeException('AeroAPI respondió vacío (HTTP ' . $status . ').');
        }
        if ($status === 401 || $status === 403) {
            throw new RuntimeException('AeroAPI rechazó la clave API (HTTP ' . $status . ').');
        }
        if ($status === 404) {
            throw new RuntimeException('AeroAPI no encontró el ident (HTTP 404).');
        }
        if ($status === 429) {
            throw new RuntimeException('AeroAPI limitó la cuota (HTTP 429).');
        }
        if ($status >= 400) {
            $detail = '';
            $decodedErr = json_decode($body, true);
            if (is_array($decodedErr)) {
                $detail = trim((string)($decodedErr['detail'] ?? $decodedErr['title'] ?? ''));
            }
            throw new RuntimeException('AeroAPI HTTP ' . $status . ($detail !== '' ? ': ' . $detail : ''));
        }
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('AeroAPI no devolvió JSON válido.');
        }
        return $decoded;
    }
}
