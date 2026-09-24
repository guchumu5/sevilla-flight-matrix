<?php
declare(strict_types=1);

namespace SevillaMatrix\Api;

use RuntimeException;

final class AirLabsClient
{
    public function __construct(private readonly string $apiKey)
    {
    }

    public function flight(string $flightCode): ?array
    {
        if ($this->apiKey === '') return null;

        $flightCode = strtoupper(preg_replace('/\s+/', '', trim($flightCode)) ?? '');
        if (!preg_match('/^(?:[A-Z]{3}|[A-Z0-9]{2})[0-9]{1,4}[A-Z]?$/', $flightCode)) {
            throw new RuntimeException('Código de vuelo AirLabs no válido: ' . $flightCode);
        }

        // Aena suele publicar el operador con prefijo ICAO de tres letras
        // (VLG, RYR, BAW...). AirLabs exige distinguirlo de los códigos IATA
        // de dos caracteres (VY, FR, BA...).
        $parameter = preg_match('/^[A-Z]{3}[0-9]/', $flightCode) === 1
            ? 'flight_icao'
            : 'flight_iata';
        $url = 'https://airlabs.co/api/v9/flight?' . http_build_query([
            $parameter => $flightCode,
            'api_key' => $this->apiKey,
        ]);
        $data = $this->get($url);
        if (isset($data['error'])) {
            $message = is_array($data['error'])
                ? (string)($data['error']['message'] ?? $data['error']['code'] ?? 'respuesta de error')
                : (string)$data['error'];
            throw new RuntimeException('AirLabs: ' . $message);
        }
        if (!isset($data['response']) || !is_array($data['response']) || $data['response'] === []) {
            return null;
        }

        $data['response']['_lookup'] = [
            'parameter' => $parameter,
            'code' => $flightCode,
        ];
        return $data['response'];
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
