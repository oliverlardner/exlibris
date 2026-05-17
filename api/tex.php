<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/common.php';
require_once __DIR__ . '/../lib/formatter.php';
require_once __DIR__ . '/../lib/embeddings.php';
require_once __DIR__ . '/../lib/tex.php';

ensure_defaults();
require_admin_for_write();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

$sourceId = (int) ($_POST['source_id'] ?? 0);
if ($sourceId <= 0) {
    json_response(['error' => 'Valid source_id is required'], 422);
}
$row = get_source($sourceId);
if (!is_array($row)) {
    json_response(['error' => 'Source not found'], 404);
}

// Accept the historical `tex_zip` field name from older clients alongside
// the broader `tex_archive` so a stale page in another tab keeps working.
$file = $_FILES['tex_archive'] ?? $_FILES['tex_zip'] ?? null;
if (!is_array($file)) {
    json_response(['error' => 'Upload a TeX source archive (.zip / .tar.gz / .tar) in the tex_archive field.'], 422);
}
$err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
if ($err === UPLOAD_ERR_NO_FILE) {
    json_response(['error' => 'No file uploaded.'], 422);
}
if ($err !== UPLOAD_ERR_OK) {
    $msg = match ($err) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Upload too large (check PHP upload_max_filesize / post_max_size).',
        UPLOAD_ERR_PARTIAL => 'Upload was interrupted — try again.',
        default => 'File upload failed (code ' . (string) $err . ').',
    };
    json_response(['error' => $msg], 400);
}

$tmpSrc = (string) ($file['tmp_name'] ?? '');
if ($tmpSrc === '' || !is_uploaded_file($tmpSrc)) {
    json_response(['error' => 'Invalid upload.'], 400);
}

$size = filesize($tmpSrc);
if ($size === false || $size < 4) {
    json_response(['error' => 'Uploaded file is empty.'], 422);
}
if ($size > TEX_ARCHIVE_MAX_TOTAL_BYTES) {
    json_response(['error' => 'Archive too large (>' . number_format(TEX_ARCHIVE_MAX_TOTAL_BYTES) . ' bytes).'], 413);
}

// Reject obvious non-archives before we move the upload — `tex_detect_archive_format`
// is the source of truth, so we run it twice (once on the upload, once on the
// staged copy) and short-circuit on the first failure for a cleaner error.
$formatProbe = tex_detect_archive_format($tmpSrc);
if ($formatProbe === '') {
    $originalName = (string) ($file['name'] ?? 'upload');
    json_response(['error' => 'Expected a TeX source archive (.zip, .tar, .tar.gz, or .tar.bz2). Got ' . $originalName . '.'], 422);
}

$tmpDir = sys_get_temp_dir();
$dest = tempnam($tmpDir, 'exlibris-tex-');
if ($dest === false) {
    json_response(['error' => 'Could not allocate temp file for upload.'], 500);
}
@unlink($dest);
$suffix = match ($formatProbe) {
    'zip' => '.zip',
    'tar' => '.tar',
    'tar.gz' => '.tar.gz',
    'tar.bz2' => '.tar.bz2',
    default => '.bin',
};
$dest .= $suffix;

if (!move_uploaded_file($tmpSrc, $dest)) {
    json_response(['error' => 'Could not stage uploaded archive.'], 500);
}

$mediaDir = source_media_dir($sourceId);
// Wipe the previous run's staged images so a re-upload doesn't accumulate
// orphans. Only basenames under the bucket are touched — the directory
// is per-source so collisions across sources are impossible.
foreach ((array) glob($mediaDir . '/*') as $stale) {
    if (is_file($stale)) {
        @unlink($stale);
    }
}

try {
    $result = tex_archive_extract_to_text($dest, [
        'media_dir' => $mediaDir,
        'media_url_base' => '/api/media.php?id=' . $sourceId . '&file=',
    ]);
} catch (Throwable $e) {
    @unlink($dest);
    app_log('tex_extract_failed', ['source_id' => $sourceId, 'error' => $e->getMessage()]);
    json_response(['error' => $e->getMessage()], 422);
}
@unlink($dest);

$plain = trim((string) ($result['body_text'] ?? ''));
if ($plain === '') {
    json_response(['error' => 'Could not extract readable text from the TeX source.'], 422);
}

$source = source_to_array($row);
$source['body_text'] = $plain;
$source['body_source'] = 'tex_' . str_replace('.', '_', (string) ($result['format'] ?? 'archive'));
$source['body_fetched_at'] = gmdate('c');

$saved = save_source($source);
$id = (int) $saved['id'];

regenerate_citation_cache_for_source($id);
upsert_source_embedding($id);

$media = array_values((array) ($result['media'] ?? []));

app_log('tex_extract_ok', [
    'source_id' => $id,
    'format' => (string) ($result['format'] ?? ''),
    'main_file' => (string) ($result['main_file'] ?? ''),
    'files' => (array) ($result['files'] ?? []),
    'chars' => mb_strlen($plain),
    'media_count' => count($media),
]);

json_response([
    'ok' => true,
    'source' => source_to_array($saved),
    'chars' => mb_strlen($plain),
    'format' => (string) ($result['format'] ?? ''),
    'main_file' => (string) ($result['main_file'] ?? ''),
    'files' => array_values((array) ($result['files'] ?? [])),
    'media' => $media,
    'change_summary' => (string) ($result['change_summary'] ?? ''),
]);
