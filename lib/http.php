<?php
declare(strict_types=1);

function http_should_retry(int $code): bool
{
    return in_array($code, [408, 425, 429, 500, 502, 503, 504], true);
}

function http_get_json(string $url, array $headers = [], int $timeout = 20, int $retries = 1): array
{
    $headers[] = 'Accept: application/json';
    $attempts = max(1, $retries + 1);
    $lastError = 'Unknown HTTP GET error';

    for ($attempt = 1; $attempt <= $attempts; $attempt++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => $headers,
        ]);

        $response = curl_exec($ch);
        if ($response === false) {
            $lastError = 'HTTP GET failed: ' . curl_error($ch);
            curl_close($ch);
            if ($attempt < $attempts) {
                usleep(200000 * $attempt);
                continue;
            }
            throw new RuntimeException($lastError);
        }

        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($code >= 200 && $code < 300) {
            $decoded = json_decode($response, true);
            if (!is_array($decoded)) {
                throw new RuntimeException('Invalid JSON response');
            }

            return $decoded;
        }

        $lastError = 'HTTP GET returned status ' . $code;
        if ($attempt < $attempts && http_should_retry($code)) {
            usleep(250000 * $attempt);
            continue;
        }

        throw new RuntimeException($lastError);
    }

    throw new RuntimeException($lastError);
}

function http_post_json(string $url, array $payload, array $headers = [], int $timeout = 30, int $retries = 1): array
{
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if ($body === false) {
        throw new RuntimeException('Unable to encode JSON payload');
    }

    $headers[] = 'Content-Type: application/json';
    $headers[] = 'Accept: application/json';

    $attempts = max(1, $retries + 1);
    $lastError = 'Unknown HTTP POST error';
    for ($attempt = 1; $attempt <= $attempts; $attempt++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $body,
        ]);

        $response = curl_exec($ch);
        if ($response === false) {
            $lastError = 'HTTP POST failed: ' . curl_error($ch);
            curl_close($ch);
            if ($attempt < $attempts) {
                usleep(250000 * $attempt);
                continue;
            }
            throw new RuntimeException($lastError);
        }

        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($code >= 200 && $code < 300) {
            $decoded = json_decode($response, true);
            if (!is_array($decoded)) {
                throw new RuntimeException('Invalid JSON response');
            }

            return $decoded;
        }

        $lastError = 'HTTP POST returned status ' . $code;
        if ($attempt < $attempts && http_should_retry($code)) {
            usleep(300000 * $attempt);
            continue;
        }
        throw new RuntimeException($lastError);
    }

    throw new RuntimeException($lastError);
}
