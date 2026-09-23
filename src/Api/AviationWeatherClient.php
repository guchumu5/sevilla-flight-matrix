<?php
declare(strict_types=1);

namespace SevillaMatrix\Api;

use RuntimeException;

final class AviationWeatherClient
{
    public function metar(string $station = 'LEZL'): ?array
    {
        $url = 'https://aviationweather.gov/api/data/metar?' . http_build_query([
            'ids' => strtoupper($station),
            'format' => 'json',
        ]);
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'User-Agent: SevillaMatrix/1.0'],
        ]);
        $body = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        if ($status === 204) return null;
        if ($body === false || $status >= 400) throw new RuntimeException("AviationWeather HTTP {$status}");
        $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        return $data[0] ?? null;
    }
}
