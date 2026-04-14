<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/common.php';
require_once __DIR__ . '/../lib/formatter.php';

ensure_defaults();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['error' => 'Method not allowed'], 405);
}

$format = strtolower(trim((string) ($_GET['format'] ?? current_citation_format())));
if (!in_array($format, ['apa', 'mla', 'chicago'], true)) {
    $format = 'apa';
}

$sourceId = (int) ($_GET['id'] ?? 0);

if ($sourceId > 0) {
    $row = get_source($sourceId);
    if (!is_array($row)) {
        json_response(['error' => 'Source not found'], 404);
    }

    $source = source_to_array($row);
    $citation = format_citation($source, $format);
    json_response([
        'ok' => true,
        'format' => $format,
        'citation' => $citation,
    ]);
}

$rows = list_sources();
$lines = [];
foreach ($rows as $row) {
    $source = source_to_array($row);
    $lines[] = format_citation($source, $format);
}

json_response([
    'ok' => true,
    'format' => $format,
    'count' => count($lines),
    'citations' => $lines,
    'text' => implode("\n", array_filter($lines)),
]);
