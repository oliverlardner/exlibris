<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/common.php';

ensure_defaults();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

require_admin_for_write();

$payload = json_input();
$format = strtolower(trim((string) ($payload['format'] ?? 'sql')));
if (!in_array($format, ['sql', 'custom'], true)) {
    json_response(['error' => 'format must be "sql" or "custom"'], 422);
}

$config = require __DIR__ . '/../config.php';
$db = $config['db'];
$bin = trim((string) (getenv('EXLIBRIS_PG_DUMP') ?: 'pg_dump'));
if ($bin === '' || str_contains($bin, "\0")) {
    json_response(['error' => 'Invalid EXLIBRIS_PG_DUMP value'], 422);
}

$tmpDir = sys_get_temp_dir();
$tmpBase = tempnam($tmpDir, 'exlibris-pgdump-');
if ($tmpBase === false) {
    json_response(['error' => 'Could not allocate a temp file'], 500);
}
unlink($tmpBase);
$outFile = $tmpBase . ($format === 'custom' ? '.dump' : '.sql');

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
    '-f',
    $outFile,
];
if ($format === 'custom') {
    array_splice($cmd, -2, 0, ['-Fc']);
}

$env = exlibris_pg_proc_env((string) $db['pass']);
$argvDisplay = exlibris_pg_argv_display($cmd);

set_time_limit(600);

// On Unix, run via sh with 2>&1 so all pg_dump diagnostics land on one pipe.
// Some PHP/Valet setups otherwise yield exit 1 with empty stderr/stdout (pipe handling).
$useShellMerge = PHP_OS_FAMILY !== 'Windows';
if ($useShellMerge) {
    $descriptorspec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
    ];
    $shellLine = exlibris_pg_argv_display($cmd) . ' 2>&1';
    $process = proc_open(['/bin/sh', '-c', $shellLine], $descriptorspec, $pipes, null, $env);
} else {
    $descriptorspec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($cmd, $descriptorspec, $pipes, null, $env);
}
if (!is_resource($process)) {
    $ts = exlibris_pg_cli_troubleshoot(
        $bin,
        -1,
        '',
        '',
        $argvDisplay,
        (string) $db['host'],
        (int) $db['port'],
        (string) $db['user'],
        (string) $db['name'],
    );
    app_log('backup_pg_dump_proc_open_failed', ['detail' => $ts]);
    json_response(
        [
            'error' => 'Could not start pg_dump.',
            'troubleshoot' => $ts,
        ],
        500
    );
}

fclose($pipes[0]);
if ($useShellMerge) {
    $merged = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stdout = '';
    $stderr = $merged;
} else {
    [$stdout, $stderr] = exlibris_proc_read_pipes($pipes[1], $pipes[2]);
}
$rawStatus = proc_close($process);
$exitCode = proc_close_exit_code($rawStatus);

$okFile = is_file($outFile) && filesize($outFile) > 0;
if ($exitCode !== 0) {
    $okFile = false;
}
if (!$okFile) {
    if (is_file($outFile)) {
        unlink($outFile);
    }
    $detail = trim((string) $stderr);
    if ($detail === '') {
        $detail = trim((string) $stdout);
    }
    if (strlen($detail) > 2000) {
        $detail = substr($detail, 0, 2000) . '…';
    }
    $msg = 'pg_dump failed';
    if ($exitCode !== 0) {
        $msg .= ' (exit ' . (string) $exitCode . ')';
    }
    if ($detail !== '') {
        $msg .= ': ' . $detail;
    }
    $troubleshoot = exlibris_pg_cli_troubleshoot(
        $bin,
        $exitCode,
        (string) $stderr,
        (string) $stdout,
        $argvDisplay,
        (string) $db['host'],
        (int) $db['port'],
        (string) $db['user'],
        (string) $db['name'],
    );
    app_log('backup_pg_dump_failed', [
        'exit' => $exitCode,
        'detail' => $detail,
        'troubleshoot' => $troubleshoot,
    ]);
    json_response(
        [
            'error' => $msg,
            'troubleshoot' => $troubleshoot,
        ],
        500
    );
}

$stamp = gmdate('Ymd-His');
$downloadName = $format === 'custom' ? 'exlibris-backup-' . $stamp . '.dump' : 'exlibris-backup-' . $stamp . '.sql';
$mime = $format === 'custom' ? 'application/octet-stream' : 'application/sql; charset=utf-8';

header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . $downloadName . '"');
header('Content-Length: ' . (string) filesize($outFile));

readfile($outFile);
unlink($outFile);
exit;
