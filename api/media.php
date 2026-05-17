<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/common.php';

ensure_defaults();

// Read-only endpoint — no admin token check (it's only ever asked to serve
// files that already live in the per-source media bucket, written by the
// admin-protected tex.php endpoint). The realpath()/dir prefix check below
// is the actual safety boundary.

if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'HEAD') {
    http_response_code(405);
    header('Allow: GET, HEAD');
    exit;
}

$sourceId = (int) ($_GET['id'] ?? 0);
$file = trim((string) ($_GET['file'] ?? ''));
if ($sourceId <= 0 || $file === '') {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'id and file are required.';
    exit;
}

// Reject anything that looks like path traversal or a nested path. The
// staging code only ever writes flat basenames under the bucket, so we
// don't need to support subdirectories here.
$basename = basename($file);
if ($basename === '' || $basename !== $file || str_contains($basename, "\0") || str_contains($basename, '/')) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Invalid file name.';
    exit;
}

$dir = source_media_dir($sourceId);
$path = $dir . '/' . $basename;

$realDir = realpath($dir);
$realPath = realpath($path);
if ($realDir === false || $realPath === false || !str_starts_with($realPath, $realDir . DIRECTORY_SEPARATOR)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not found.';
    exit;
}
if (!is_file($realPath)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not found.';
    exit;
}

$ext = strtolower((string) pathinfo($realPath, PATHINFO_EXTENSION));
$mime = match ($ext) {
    'png' => 'image/png',
    'jpg', 'jpeg' => 'image/jpeg',
    'gif' => 'image/gif',
    'svg' => 'image/svg+xml',
    'webp' => 'image/webp',
    'avif' => 'image/avif',
    'pdf' => 'application/pdf',
    'eps', 'ps' => 'application/postscript',
    default => 'application/octet-stream',
};

$size = (int) filesize($realPath);
$mtime = (int) filemtime($realPath);
$etag = '"' . substr(md5($basename . '|' . (string) $size . '|' . (string) $mtime), 0, 16) . '"';

$ifNoneMatch = trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
if ($ifNoneMatch !== '' && $ifNoneMatch === $etag) {
    http_response_code(304);
    header('ETag: ' . $etag);
    header('Cache-Control: private, max-age=86400');
    exit;
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . $size);
header('ETag: ' . $etag);
header('Cache-Control: private, max-age=86400');
header('Content-Disposition: inline; filename="' . str_replace('"', '', $basename) . '"');
header('X-Content-Type-Options: nosniff');
// SVGs can carry inline <script>. Disable script execution and external
// loads on this response so a malicious figure can't escalate. The other
// formats are inert and ignore CSP, so it's safe to apply unconditionally.
header("Content-Security-Policy: default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; sandbox");

if ($_SERVER['REQUEST_METHOD'] === 'HEAD') {
    exit;
}

readfile($realPath);
