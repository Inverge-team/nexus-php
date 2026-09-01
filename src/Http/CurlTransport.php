<?php

declare(strict_types=1);

namespace Inverge\Nexus\Http;

use Inverge\Nexus\Contracts\Transport;
use Inverge\Nexus\Exception\TransportException;

/**
 * Zero-dependency HTTP transport built on ext-curl. The default — works in any
 * PHP environment without pulling in a client library.
 */
final class CurlTransport implements Transport
{
    public function send(string $method, string $url, array $headers, ?array $json, float $timeout): ApiResponse
    {
        $handle = curl_init($url);
        if ($handle === false) {
            throw new TransportException("Unable to initialise a cURL handle for {$url}.");
        }

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = "{$name}: {$value}";
        }

        $options = [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => (int) round($timeout * 1000),
            CURLOPT_CONNECTTIMEOUT_MS => (int) round($timeout * 1000),
            CURLOPT_HTTPHEADER => $headerLines,
        ];

        if ($json !== null) {
            $options[CURLOPT_POSTFIELDS] = self::encode($json);
        }

        curl_setopt_array($handle, $options);

        $body = curl_exec($handle);
        if ($body === false) {
            $error = curl_error($handle);
            curl_close($handle);
            throw new TransportException("HTTP {$method} {$url} failed: {$error}");
        }

        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        return new ApiResponse($status, (string) $body);
    }

    /** @param array<string, mixed> $json */
    private static function encode(array $json): string
    {
        $encoded = json_encode($json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            throw new TransportException('Failed to encode the request body as JSON: ' . json_last_error_msg());
        }

        return $encoded;
    }
}
