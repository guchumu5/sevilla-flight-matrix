<?php
declare(strict_types=1);

namespace SevillaMatrix\Api;

use RuntimeException;

final class OpenSkyClient
{
    private const TOKEN_URL = 'https://auth.opensky-network.org/auth/realms/opensky-network/protocol/openid-connect/token';
    private const API_URL = 'https://opensky-network.org/api/states/all';

    public function __construct(
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $cacheFile
    ) {
    }

    public function state(string $icao24): ?array
    {
        $result = $this->states([$icao24]);
        return $result['states'][strtolower(trim($icao24))] ?? null;
    }

    /**
     * Consulta varias matrículas en una única petición OpenSky.
     *
     * @param array<int,string> $icao24s
     * @return array{states:array<string,array<string,mixed>>,rate_limit:array<string,int|null>}
     */
    public function states(array $icao24s): array
    {
        $this->guardCooldown();
        $codes = array_values(array_unique(array_filter(array_map(
            static fn(mixed $value): string => strtolower(trim((string)$value)),
            $icao24s
        ), static fn(string $value): bool => preg_match('/^[0-9a-f]{6}$/', $value) === 1)));
        if ($codes === []) {
            return ['states' => [], 'rate_limit' => ['remaining' => null, 'retry_after_seconds' => null]];
        }

        $query = implode('&', array_map(
            static fn(string $value): string => 'icao24=' . rawurlencode($value),
            $codes
        ));
        $url = self::API_URL . '?' . $query;
        $headers = [];
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $this->token(), 'Accept: application/json'],
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$headers): int {
                $length = strlen($line);
                if (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $headers[strtolower(trim($name))] = trim($value);
                }
                return $length;
            },
        ]);
        $body = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        $retryAfter = isset($headers['x-rate-limit-retry-after-seconds'])
            ? (int)$headers['x-rate-limit-retry-after-seconds']
            : (isset($headers['retry-after']) ? (int)$headers['retry-after'] : null);
        if ($body === false || $status >= 400) {
            if ($status === 429) {
                $seconds = max(60, (int)($retryAfter ?? 900));
                $this->saveCooldown($seconds);
            }
            $suffix = $retryAfter !== null && $retryAfter > 0
                ? "; reintentar dentro de {$retryAfter} segundos"
                : ($error !== '' ? ': ' . $error : '');
            throw new RuntimeException("OpenSky HTTP {$status}{$suffix}");
        }
        $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        $states = [];
        foreach (($data['states'] ?? []) as $row) {
            if (!is_array($row) || empty($row[0])) continue;
            $code = strtolower(trim((string)$row[0]));
            $states[$code] = [
                'icao24' => $code,
                'callsign' => isset($row[1]) ? trim((string)$row[1]) : null,
                'longitude' => $row[5] ?? null,
                'latitude' => $row[6] ?? null,
                'altitude_m' => $row[7] ?? null,
                'on_ground' => $row[8] ?? null,
                'ground_speed_ms' => $row[9] ?? null,
                'track_deg' => $row[10] ?? null,
                'vertical_rate_ms' => $row[11] ?? null,
                'raw' => $row,
            ];
        }
        return [
            'states' => $states,
            'rate_limit' => [
                'remaining' => isset($headers['x-rate-limit-remaining'])
                    ? (int)$headers['x-rate-limit-remaining']
                    : null,
                'retry_after_seconds' => $retryAfter,
            ],
        ];
    }

    private function guardCooldown(): void
    {
        $file = $this->cooldownFile();
        if (!is_file($file)) return;
        $data = json_decode((string)file_get_contents($file), true);
        $retryAt = (int)($data['retry_at'] ?? 0);
        if ($retryAt <= time()) {
            @unlink($file);
            return;
        }
        $remaining = $retryAt - time();
        throw new RuntimeException("OpenSky en pausa por cuota; reintentar dentro de {$remaining} segundos");
    }

    private function saveCooldown(int $seconds): void
    {
        $file = $this->cooldownFile();
        $directory = dirname($file);
        if (!is_dir($directory)) @mkdir($directory, 0770, true);
        file_put_contents($file, json_encode([
            'retry_at' => time() + $seconds,
            'created_at' => date(DATE_ATOM),
        ], JSON_UNESCAPED_SLASHES), LOCK_EX);
    }

    private function cooldownFile(): string
    {
        return dirname($this->cacheFile) . '/opensky-cooldown.json';
    }

    private function token(): string
    {
        if (is_file($this->cacheFile)) {
            $cached = json_decode(file_get_contents($this->cacheFile) ?: '{}', true);
            if (($cached['expires_at'] ?? 0) > time() + 60 && !empty($cached['token'])) return $cached['token'];
        }
        if ($this->clientId === '' || $this->clientSecret === '') throw new RuntimeException('Faltan credenciales OpenSky.');
        $curl = curl_init(self::TOKEN_URL);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_POSTFIELDS => http_build_query([
                'grant_type' => 'client_credentials',
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
            ]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        ]);
        $body = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        if ($body === false || $status >= 400) throw new RuntimeException("OAuth OpenSky HTTP {$status}");
        $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        $payload = ['token' => $data['access_token'], 'expires_at' => time() + (int)($data['expires_in'] ?? 1800)];
        file_put_contents($this->cacheFile, json_encode($payload), LOCK_EX);
        return $payload['token'];
    }
}
