<?php
declare(strict_types=1);

namespace SevillaMatrix\Api;

use RuntimeException;

final class AirLabsClient
{
    /** @var list<string> */
    private array $apiKeys;

    /**
     * @param string|array<int,string> $apiKeys Claves pertenecientes a planes autorizados.
     */
    public function __construct(string|array $apiKeys, private readonly ?string $rotationFile = null)
    {
        $values = is_array($apiKeys) ? $apiKeys : [$apiKeys];
        $this->apiKeys = array_values(array_unique(array_filter(array_map(
            static fn(mixed $key): string => trim((string)$key),
            $values
        ))));
        if ($this->apiKeys === []) {
            throw new RuntimeException('No hay ninguna clave AirLabs configurada.');
        }
    }

    /** @return list<array<string,mixed>> */
    public function arrivals(string $airportCode = 'SVQ', int $limit = 50): array
    {
        $airportCode = strtoupper(trim($airportCode));
        if (!preg_match('/^[A-Z]{3}$/', $airportCode)) {
            throw new RuntimeException('Código de aeropuerto AirLabs no válido: ' . $airportCode);
        }

        $data = $this->request('schedules', [
            'arr_iata' => $airportCode,
            'limit' => max(1, min(50, $limit)),
        ]);
        $rows = $data['response'] ?? [];
        if (!is_array($rows)) return [];

        $slot = (int)($data['_matrix_key_slot'] ?? 0);
        $result = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $row['_airlabs_key_slot'] = $slot;
            $result[] = $row;
        }
        return $result;
    }

    public function flight(string $flightCode): ?array
    {
        $flightCode = strtoupper(preg_replace('/\s+/', '', trim($flightCode)) ?? '');
        if (!preg_match('/^(?:[A-Z]{3}|[A-Z0-9]{2})[0-9]{1,4}[A-Z]?$/', $flightCode)) {
            throw new RuntimeException('Código de vuelo AirLabs no válido: ' . $flightCode);
        }

        $parameter = preg_match('/^[A-Z]{3}[0-9]/', $flightCode) === 1
            ? 'flight_icao'
            : 'flight_iata';
        $data = $this->request('flight', [$parameter => $flightCode]);
        if (!isset($data['response']) || !is_array($data['response']) || $data['response'] === []) {
            return null;
        }

        $data['response']['_lookup'] = [
            'parameter' => $parameter,
            'code' => $flightCode,
            'key_slot' => (int)($data['_matrix_key_slot'] ?? 0),
        ];
        return $data['response'];
    }

    /** @param array<string,int|string> $parameters @return array<string,mixed> */
    private function request(string $endpoint, array $parameters): array
    {
        $start = $this->reserveKeySlot();
        $errors = [];
        $retryable = [
            'unknown_api_key',
            'expired_api_key',
            'minute_limit_exceeded',
            'hour_limit_exceeded',
            'month_limit_exceeded',
        ];

        for ($offset = 0, $count = count($this->apiKeys); $offset < $count; $offset++) {
            $index = ($start + $offset) % $count;
            $url = 'https://airlabs.co/api/v9/' . $endpoint . '?' . http_build_query(
                $parameters + ['api_key' => $this->apiKeys[$index]]
            );

            try {
                $data = $this->get($url);
            } catch (RuntimeException $error) {
                $errors[] = sprintf('clave %d: %s', $index + 1, $error->getMessage());
                continue;
            }

            if (isset($data['error'])) {
                $error = is_array($data['error']) ? $data['error'] : ['message' => (string)$data['error']];
                $code = (string)($error['code'] ?? 'unknown_error');
                $message = (string)($error['message'] ?? $code);
                $errors[] = sprintf('clave %d: %s', $index + 1, $message);
                if (in_array($code, $retryable, true)) continue;
                throw new RuntimeException('AirLabs: ' . $message);
            }

            $data['_matrix_key_slot'] = $index + 1;
            return $data;
        }

        throw new RuntimeException('AirLabs no respondió con ninguna clave configurada: ' . implode(' | ', $errors));
    }

    /** @return array<string,mixed> */
    private function get(string $url): array
    {
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'User-Agent: SevillaMatrix/1.5'],
        ]);
        $body = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        if ($body === false) {
            throw new RuntimeException("AirLabs HTTP {$status}: {$error}");
        }

        try {
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new RuntimeException("AirLabs HTTP {$status}: respuesta JSON no válida", 0, $exception);
        }
        if (!is_array($data)) {
            throw new RuntimeException("AirLabs HTTP {$status}: respuesta vacía");
        }
        if ($status >= 400 && !isset($data['error'])) {
            throw new RuntimeException("AirLabs HTTP {$status}: {$error}");
        }
        return $data;
    }

    private function reserveKeySlot(): int
    {
        $count = count($this->apiKeys);
        if ($count <= 1 || $this->rotationFile === null || $this->rotationFile === '') return 0;

        $directory = dirname($this->rotationFile);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) return 0;
        $handle = @fopen($this->rotationFile, 'c+');
        if ($handle === false) return 0;

        try {
            if (!flock($handle, LOCK_EX)) return 0;
            rewind($handle);
            $current = (int)trim(stream_get_contents($handle) ?: '0');
            $slot = (($current % $count) + $count) % $count;
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, (string)(($slot + 1) % $count));
            fflush($handle);
            flock($handle, LOCK_UN);
            return $slot;
        } finally {
            fclose($handle);
        }
    }
}
