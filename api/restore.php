<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/common.php';

/**
 * True if path looks like a pg_dump -Fc archive (starts with PGDMP).
 */
function is_pg_custom_dump_file(string $path): bool
{
    $h = fopen($path, 'rb');
    if ($h === false) {
        return false;
    }
    $sig = fread($h, 5);
    fclose($h);

    return $sig === 'PGDMP';
}

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

ensure_defaults();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

require_admin_for_write();

$ack = trim((string) ($_POST['acknowledge_danger'] ?? ''));
if ($ack !== '1') {
    json_response(['error' => 'Confirm that you understand this overwrites data in the configured database.'], 422);
}

if (!isset($_FILES['dump']) || !is_array($_FILES['dump'])) {
    json_response(['error' => 'Upload a dump file (field name: dump).'], 422);
}

$file = $_FILES['dump'];
$err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
if ($err === UPLOAD_ERR_NO_FILE) {
    json_response(['error' => 'No file uploaded.'], 422);
}
if ($err !== UPLOAD_ERR_OK) {
    $msg = match ($err) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Upload too large (check PHP upload_max_filesize / post_max_size).',
        default => 'File upload failed (code ' . (string) $err . ').',
    };
    json_response(['error' => $msg], 400);
}

$tmpSrc = (string) ($file['tmp_name'] ?? '');
if ($tmpSrc === '' || !is_uploaded_file($tmpSrc)) {
    json_response(['error' => 'Invalid upload.'], 400);
}

$maxBytes = (int) (getenv('EXLIBRIS_RESTORE_MAX_BYTES') ?: 536870912);
if ($maxBytes < 1048576) {
    $maxBytes = 536870912;
}
$size = filesize($tmpSrc);
if ($size === false || $size < 1) {
    json_response(['error' => 'Dump file is empty.'], 422);
}
if ($size > $maxBytes) {
    json_response(['error' => 'Dump file exceeds EXLIBRIS_RESTORE_MAX_BYTES (' . (string) $maxBytes . ').'], 413);
}

$formatHint = strtolower(trim((string) ($_POST['format'] ?? 'auto')));
if (!in_array($formatHint, ['auto', 'sql', 'custom'], true)) {
    json_response(['error' => 'format must be auto, sql, or custom'], 422);
}

$probeCustom = is_pg_custom_dump_file($tmpSrc);
if ($formatHint === 'sql' && $probeCustom) {
    json_response(['error' => 'This file is a PostgreSQL custom-format dump. Choose Custom or Auto.'], 422);
}
if ($formatHint === 'custom' && !$probeCustom) {
    json_response(['error' => 'This file is not a PostgreSQL custom-format dump (expected PGDMP header).'], 422);
}

$isCustom = $formatHint === 'custom' || ($formatHint === 'auto' && $probeCustom);

$config = require __DIR__ . '/../config.php';
$db = $config['db'];

$tmpDir = sys_get_temp_dir();
$dest = tempnam($tmpDir, 'exlibris-restore-');
if ($dest === false) {
    json_response(['error' => 'Could not allocate a temp file'], 500);
}
unlink($dest);
$dest .= $isCustom ? '.dump' : '.sql';

if (!move_uploaded_file($tmpSrc, $dest)) {
    json_response(['error' => 'Could not store upload for restore.'], 500);
}

$env = getenv();
if (!is_array($env)) {
    $env = $_ENV;
}
$env['PGPASSWORD'] = (string) $db['pass'];
$env['PGGSSENCMODE'] = 'disable';

set_time_limit(3600);

if ($isCustom) {
    $bin = trim((string) (getenv('EXLIBRIS_PG_RESTORE') ?: 'pg_restore'));
    if ($bin === '' || str_contains($bin, "\0")) {
        unlink($dest);
        json_response(['error' => 'Invalid EXLIBRIS_PG_RESTORE value'], 422);
    }
    $cmd = [
        $bin,
        '-h',
        (string) $db['host'],
        '-p',
        (string) (int) $db['port'],
        '-U',
        (string) $db['user'],
        '-d',
        (string) $db['name'],
        '--no-owner',
        '--no-acl',
        '--clean',
        '--if-exists',
        $dest,
    ];
} else {
    $bin = trim((string) (getenv('EXLIBRIS_PSQL') ?: 'psql'));
    if ($bin === '' || str_contains($bin, "\0")) {
        unlink($dest);
        json_response(['error' => 'Invalid EXLIBRIS_PSQL value'], 422);
    }
    $cmd = [
        $bin,
        '-h',
        (string) $db['host'],
        '-p',
        (string) (int) $db['port'],
        '-U',
        (string) $db['user'],
        '-d',
        (string) $db['name'],
        '-v',
        'ON_ERROR_STOP=1',
        '-f',
        $dest,
    ];
}

$descriptorspec = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];

$process = proc_open($cmd, $descriptorspec, $pipes, null, $env);
if (!is_resource($process)) {
    unlink($dest);
    json_response(['error' => 'Could not start ' . ($isCustom ? 'pg_restore' : 'psql')], 500);
}

fclose($pipes[0]);
$stdout = stream_get_contents($pipes[1]);
fclose($pipes[1]);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[2]);
$rawStatus = proc_close($process);
unlink($dest);

$exitCode = proc_close_exit_code($rawStatus);

$out = trim((string) $stdout);
$errText = trim((string) $stderr);
$combined = trim($errText !== '' ? $errText : $out);
if ($combined === '') {
    $combined = $isCustom ? 'pg_restore finished.' : 'psql finished.';
}
if (strlen($combined) > 8000) {
    $combined = substr($combined, 0, 8000) . '…';
}

if ($exitCode !== 0) {
    app_log('restore_failed', ['custom' => $isCustom, 'exit' => $exitCode]);
    json_response(['error' => $combined !== '' ? $combined : 'Restore failed (exit ' . (string) $exitCode . ').'], 500);
}

app_log('restore_ok', ['custom' => $isCustom]);
json_response([
    'ok' => true,
    'message' => $isCustom
        ? 'Custom-format dump restored (existing objects were dropped where needed).'
        : 'SQL dump applied. If the database was not empty, errors may have stopped the run early — check the log.',
    'log' => $combined,
]);
