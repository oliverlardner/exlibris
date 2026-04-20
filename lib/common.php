<?php
declare(strict_types=1);

(static function (): void {
    $envFile = __DIR__ . '/../.env';
    if (!is_file($envFile)) {
        return;
    }
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        return;
    }
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        $eqPos = strpos($line, '=');
        if ($eqPos === false) {
            continue;
        }
        $key = trim(substr($line, 0, $eqPos));
        $val = trim(substr($line, $eqPos + 1));
        if ($val !== '' && $val[0] === '"' && $val[strlen($val) - 1] === '"') {
            $val = stripslashes(substr($val, 1, -1));
        } elseif ($val !== '' && $val[0] === "'" && $val[strlen($val) - 1] === "'") {
            $val = substr($val, 1, -1);
        }
        if ($key !== '' && getenv($key) === false) {
            putenv($key . '=' . $val);
            $_ENV[$key] = $val;
        }
    }
})();

require_once __DIR__ . '/db.php';

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function current_citation_format(): string
{
    $value = strtolower((string) setting('citation_format', 'apa'));
    if (!in_array($value, ['apa', 'mla', 'chicago'], true)) {
        return 'apa';
    }

    return $value;
}

function current_theme_mode(): string
{
    $value = strtolower((string) setting('theme_mode', 'auto'));
    if (!in_array($value, ['auto', 'light', 'dark'], true)) {
        return 'auto';
    }

    return $value;
}

function include_pages_in_citations(): bool
{
    $value = trim((string) setting('include_pages_in_citations', '1'));

    return !in_array($value, ['0', 'false', 'no', 'off'], true);
}

function json_input(): array
{
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [];
    }

    return $decoded;
}

function json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function config_value(string $group, string $key, mixed $default = null): mixed
{
    $config = require __DIR__ . '/../config.php';

    return $config[$group][$key] ?? $default;
}

function require_admin_for_write(): void
{
    if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
        return;
    }

    $token = trim((string) config_value('security', 'admin_token', ''));
    if ($token === '') {
        json_response([
            'error' => 'Server admin token is not configured. Set EXLIBRIS_ADMIN_TOKEN to enable write endpoints.',
        ], 503);
    }

    $provided = trim((string) ($_SERVER['HTTP_X_ADMIN_TOKEN'] ?? ''));
    if ($provided === '') {
        $authHeader = trim((string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
        if (str_starts_with(strtolower($authHeader), 'bearer ')) {
            $provided = trim(substr($authHeader, 7));
        }
    }

    if (!hash_equals($token, $provided)) {
        json_response(['error' => 'Unauthorized'], 401);
    }
}

function app_log(string $event, array $context = []): void
{
    $line = json_encode([
        'ts' => gmdate('c'),
        'event' => $event,
        'context' => $context,
    ], JSON_UNESCAPED_UNICODE);
    if (!is_string($line)) {
        return;
    }

    error_log('[exlibris] ' . $line);
}

function safe_external_url(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }

    $parsed = parse_url($url);
    if (!is_array($parsed)) {
        return '';
    }

    $scheme = strtolower((string) ($parsed['scheme'] ?? ''));
    if (!in_array($scheme, ['http', 'https'], true)) {
        return '';
    }

    return $url;
}
