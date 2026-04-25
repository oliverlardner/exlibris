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

/**
 * Best-effort exit code from proc_close() (Unix wait status vs Windows).
 */
function proc_close_exit_code(int $status): int
{
    if (PHP_OS_FAMILY === 'Windows') {
        return $status;
    }
    if (function_exists('pcntl_wifexited') && pcntl_wifexited($status)) {
        return pcntl_wexitstatus($status);
    }

    return $status !== 0 ? 1 : 0;
}

/**
 * Environment for libpq-based CLI (pg_dump, psql, pg_restore) run via proc_open.
 * PHP-FPM often passes an empty environment; without PATH, bare "pg_dump" is not found.
 * Optional: EXLIBRIS_PGSSLMODE or PGSSLMODE for hosted Postgres.
 */
function exlibris_pg_proc_env(string $pgPassword): array
{
    $env = getenv();
    if (!is_array($env) || $env === []) {
        $env = is_array($_ENV) ? $_ENV : [];
    }
    $path = $env['PATH'] ?? null;
    if (!is_string($path) || $path === '') {
        $fromServer = $_SERVER['PATH'] ?? '';
        if (is_string($fromServer) && $fromServer !== '') {
            $env['PATH'] = $fromServer;
        } elseif (PHP_OS_FAMILY === 'Windows') {
            $env['PATH'] = 'C:\Windows\System32';
        } else {
            $env['PATH'] = '/usr/local/bin:/usr/bin:/bin';
        }
    }
    // Valet/php-fpm often omit Homebrew from PATH; pg_dump lives under /opt/homebrew (ARM) or /usr/local (Intel).
    if (PHP_OS_FAMILY === 'Darwin') {
        $brew = '/opt/homebrew/bin:/usr/local/bin';
        $p = isset($env['PATH']) && is_string($env['PATH']) ? $env['PATH'] : '';
        $env['PATH'] = $p === '' ? $brew . ':/usr/bin:/bin' : $brew . ':' . $p;
    }
    $env['PGPASSWORD'] = $pgPassword;
    $env['PGGSSENCMODE'] = 'disable';
    $ssl = getenv('EXLIBRIS_PGSSLMODE');
    if (is_string($ssl) && $ssl !== '') {
        $env['PGSSLMODE'] = $ssl;
    } else {
        $pgssl = getenv('PGSSLMODE');
        if (is_string($pgssl) && $pgssl !== '') {
            $env['PGSSLMODE'] = $pgssl;
        }
    }

    return $env;
}

/**
 * Read proc_open stdout + stderr. Read stderr first: if we block on an empty
 * stdout first, a chatty child can fill the stderr pipe and deadlock (e.g. pg_dump).
 *
 * @param resource $stdoutPipe
 * @param resource $stderrPipe
 * @return array{0: string, 1: string} stdout, stderr
 */
function exlibris_proc_read_pipes($stdoutPipe, $stderrPipe): array
{
    $stderr = is_resource($stderrPipe) ? (string) stream_get_contents($stderrPipe) : '';
    if (is_resource($stderrPipe)) {
        fclose($stderrPipe);
    }
    $stdout = is_resource($stdoutPipe) ? (string) stream_get_contents($stdoutPipe) : '';
    if (is_resource($stdoutPipe)) {
        fclose($stdoutPipe);
    }

    return [$stdout, $stderr];
}

/**
 * Human-readable arg list for logs / errors (not for shell execution).
 *
 * @param list<string> $argv
 */
function exlibris_pg_argv_display(array $argv): string
{
    $out = [];
    foreach ($argv as $a) {
        $s = (string) $a;
        $out[] = preg_match('/[\s\\\\\'"]/', $s) > 0 ? escapeshellarg($s) : $s;
    }

    return implode(' ', $out);
}

/**
 * Extra context when pg_dump / psql / pg_restore fails (for API errors and app_log).
 */
function exlibris_pg_cli_troubleshoot(
    string $binary,
    int $exitCode,
    string $stderr,
    string $stdout,
    string $argvDisplay,
    string $host,
    int $port,
    string $user,
    string $dbname,
): string {
    if ($exitCode < 0) {
        $parts = [
            sprintf('PHP could not start the process (proc_open failed). Binary was %s; target %s:%d, database %s, user %s.', $binary, $host, $port, $dbname, $user),
            'Check that PHP allows proc_open (not in disable_functions), the path in EXLIBRIS_PG_DUMP is correct if the default pg_dump is not found, and the binary is executable.',
        ];
        $cmdLine = strlen($argvDisplay) > 500 ? substr($argvDisplay, 0, 500) . '…' : $argvDisplay;
        $parts[] = 'Command line: `' . $cmdLine . '`.';
        $early = implode(' ', $parts);

        return strlen($early) > 4000 ? substr($early, 0, 4000) . '…' : $early;
    }

    $detail = trim($stderr) !== '' ? $stderr : $stdout;
    $blob = strtolower($detail);

    $parts = [
        sprintf('Connecting as %s to %s:%d, database %s; pg_dump binary was %s.', $user, $host, $port, $dbname, $binary),
    ];

    if (trim($detail) === '') {
        $parts[] = 'No stderr/stdout from pg_dump (rare; exit ' . (string) $exitCode . ').';
    }

    $hints = [];
    if (trim($detail) === '' || str_contains($blob, 'command not found') || str_contains($blob, 'no such file or directory') || str_contains($blob, 'is not recognized')) {
        $hints[] = 'Install client tools, or set EXLIBRIS_PG_DUMP to the full path to pg_dump (e.g. `which pg_dump` in a terminal).';
    }
    if (str_contains($blob, 'password authentication failed') || str_contains($blob, 'fe_sendauth')) {
        $hints[] = 'Verify EXLIBRIS_DB_USER and EXLIBRIS_DB_PASS in .env match a PostgreSQL role.';
    }
    if (str_contains($blob, 'could not connect') || str_contains($blob, 'connection refused') || str_contains($blob, 'could not translate host name') || str_contains($blob, ' Name or service not known')) {
        $hints[] = 'Confirm EXLIBRIS_DB_HOST / EXLIBRIS_DB_PORT, and that the server is running and allows this host (pg_hba.conf / firewall).';
    }
    if (str_contains($blob, 'pg_hba.conf') && str_contains($blob, 'no entry')) {
        $hints[] = 'PostgreSQL pg_hba.conf may not allow this user/host combination.';
    }
    if (preg_match('/SSL|TLS|GSS|encryption|no pg_hba|certificate/i', $detail) > 0) {
        $hints[] = 'If the server requires SSL, set EXLIBRIS_PGSSLMODE=require (or set PGSSLMODE) in your environment.';
    }
    if (str_contains($blob, 'role') && str_contains($blob, 'does not exist')) {
        $hints[] = 'Create the DB role or set EXLIBRIS_DB_USER to an existing role.';
    }
    if (str_contains($blob, 'database') && str_contains($blob, 'does not exist')) {
        $hints[] = 'Create the database or fix EXLIBRIS_DB_NAME.';
    }

    $hints = array_values(array_unique($hints));
    if ($hints !== []) {
        $parts[] = 'What to check: ' . implode(' ', array_map(
            static fn (string $h, int $i): string => (string) ($i + 1) . ') ' . $h,
            $hints,
            array_keys($hints)
        ));
    }

    $parts[] = 'Manual test: same env vars, run `' . (strlen($argvDisplay) > 500 ? substr($argvDisplay, 0, 500) . '…' : $argvDisplay) . '` (set PGPASSWORD in the shell; avoid pasting the password into logs).';

    $out = implode(' ', $parts);
    if (strlen($out) > 4000) {
        $out = substr($out, 0, 4000) . '…';
    }

    return $out;
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function supported_citation_formats(): array
{
    return [
        'apa' => 'APA',
        'mla' => 'MLA',
        'chicago18' => 'Chicago 18',
    ];
}

function normalize_citation_format(string $value, string $default = 'apa'): string
{
    $value = strtolower(trim($value));
    $aliases = [
        'chicago' => 'chicago18',
    ];
    if (isset($aliases[$value])) {
        $value = $aliases[$value];
    }

    $formats = supported_citation_formats();
    if (!array_key_exists($value, $formats)) {
        if ($default === '') {
            return '';
        }

        return array_key_exists($default, $formats) ? $default : 'apa';
    }

    return $value;
}

function citation_format_label(string $format): string
{
    $formats = supported_citation_formats();
    $normalized = normalize_citation_format($format);

    return $formats[$normalized] ?? $formats['apa'];
}

function current_citation_format(): string
{
    return normalize_citation_format((string) setting('citation_format', 'apa'));
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
