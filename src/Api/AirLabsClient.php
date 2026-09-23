<?php
declare(strict_types=1);

namespace SevillaMatrix\Api;

use RuntimeException;

final class AirLabsClient
{
    public function __construct(private readonly string $apiKey)
    {
    }

    public function flight(string $flightIata): ?array
    {
        if ($this->apiKey === '') return null;
        $url = 'https://airlabs.co/api/v9/flight?' . http_build_query([
            'flight_iata' => $flightIata,
            'api_key' => $this->apiKey,
        ]);
        $data = $this->get($url);
        return isset($data['response']) && is_array($data['response']) ? $data['response'] : null;
    }

    private function get(string $url): array
    {
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'User-Agent: SevillaMatrix/1.0'],
        ]);
        $body = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        if ($body === false || $status >= 400) {
            throw new RuntimeException("AirLabs HTTP {$status}: {$error}");
        }
        return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    }
}

