<?php
declare(strict_types=1);

namespace SevillaMatrix\Sync;

use SevillaMatrix\IataFlightCode;

final class FlightStatsClient
{
    private const USER_AGENT = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/140 Safari/537.36 SevillaFlightMatrix/1.0';

    /**
     * @param array<string,mixed> $flight
     * @return array<string,mixed>
     */
    public function fetch(array $flight): array
    {
        $carrier = strtoupper(trim((string)($flight['iata_carrier'] ?? '')));
        $number = trim((string)($flight['iata_number'] ?? ''));
        $parts = [
            'year' => (int)($flight['year'] ?? 0),
            'month' => (int)($flight['month'] ?? 0),
            'date' => (int)($flight['date'] ?? 0),
        ];
        if (!preg_match('/^[A-Z][A-Z0-9]$/', $carrier) || !preg_match('/^\d{1,4}[A-Z]?$/', $number)
            || $parts['year'] < 2020 || $parts['month'] < 1 || $parts['date'] < 1) {
            throw new \RuntimeException('Faltan el código IATA o la fecha para consultar FlightStats.');
        }
        $publicUrl = IataFlightCode::trackerUrl($carrier, $number, $parts);
        $jsonUrl = IataFlightCode::jsonUrl($carrier, $number, $parts);
        $scheduled = (string)($flight['scheduled_arrival'] ?? '');

        $jsonBody = $this->httpGet($jsonUrl, $publicUrl, true);
        if (is_array($jsonBody)) {
            $parsed = FlightStatsParser::fromJson($jsonBody, $publicUrl);
            if (is_array($parsed)) {
                $parsed['public_url'] = $publicUrl;
                $parsed['source_kind'] = 'json';
                return $parsed;
            }
        }

        $html = $this->httpGet($publicUrl, $publicUrl, false);
        if (!is_string($html) || $html === '') {
            throw new \RuntimeException('FlightStats no devolvió JSON ni HTML utilizable.');
        }
        $parsed = FlightStatsParser::fromHtml($html, $scheduled, $publicUrl);
        if (!is_array($parsed)) {
            throw new \RuntimeException('La ficha pública de FlightStats no presentó retraso ni ETA.');
        }
        $parsed['public_url'] = $publicUrl;
        $parsed['source_kind'] = 'html';
        return $parsed;
    }

    /** @return array<string,mixed>|string|null */
    private function httpGet(string $url, string $referer, bool $json)
    {
        $curl = curl_init($url);
        if ($curl === false) return null;
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_HTTPHEADER => $json
                ? ['Accept: application/json,text/plain,*/*', 'Referer: ' . $referer]
                : ['Accept: text/html,application/xhtml+xml', 'Referer: ' . $referer],
        ]);
        $body = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $type = (string)curl_getinfo($curl, CURLINFO_CONTENT_TYPE);
        curl_close($curl);
        if (!is_string($body) || $body === '' || $status >= 400) return null;
        if ($json) {
            if (!str_contains(strtolower($type), 'json') && !str_starts_with(ltrim($body), '{')) return null;
            $decoded = json_decode($body, true);
            return is_array($decoded) ? $decoded : null;
        }
        return $body;
    }
}
