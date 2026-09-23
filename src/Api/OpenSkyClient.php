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
        $url = self::API_URL . '?' . http_build_query(['icao24' => strtolower($icao24)]);
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $this->token(), 'Accept: application/json'],
        ]);
        $body = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        if ($body === false || $status >= 400) throw new RuntimeException("OpenSky HTTP {$status}");
        $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        $row = $data['states'][0] ?? null;
        if (!is_array($row)) return null;
        return [
            'icao24' => $row[0] ?? null,
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

