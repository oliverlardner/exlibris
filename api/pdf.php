<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/common.php';
require_once __DIR__ . '/../lib/formatter.php';
require_once __DIR__ . '/../lib/pdf.php';

ensure_defaults();
require_admin_for_write();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

$payload = json_input();
$id = (int) ($payload['id'] ?? 0);
$action = trim((string) ($payload['action'] ?? ''));
if ($id <= 0) {
    json_response(['error' => 'Valid source id is required'], 422);
}
if (!in_array($action, ['open', 'extract'], true)) {
    json_response(['error' => 'Unsupported PDF action'], 422);
}

$row = get_source($id);
if (!is_array($row)) {
    json_response(['error' => 'Source not found'], 404);
}
$source = source_to_array($row);
$pdfPath = trim((string) ($source['pdf_path'] ?? ''));
if ($pdfPath === '' || !is_file($pdfPath)) {
    json_response(['error' => 'Source does not have a local PDF path'], 404);
}

if ($action === 'open') {
    try {
        pdf_open_in_finder($pdfPath);
    } catch (Throwable $e) {
        json_response(['error' => $e->getMessage()], 500);
    }
    json_response(['ok' => true, 'action' => 'open']);
}

try {
    $text = pdf_extract_text($pdfPath);
} catch (Throwable $e) {
    json_response(['error' => $e->getMessage()], 500);
}
if ($text === '') {
    json_response(['error' => 'No extractable text found in PDF'], 422);
}

$source['body_text'] = $text;
$source['body_source'] = 'pdf_ocr';
$source['body_fetched_at'] = gmdate('c');
$saved = save_source($source);

json_response([
    'ok' => true,
    'action' => 'extract',
    'chars' => strlen($text),
    'source' => source_to_array($saved),
]);
