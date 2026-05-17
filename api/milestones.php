<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/common.php';
require_once __DIR__ . '/../lib/milestones.php';

ensure_defaults();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $document = milestones_normalize(setting('milestones_timeline'));
    json_response(array_merge(['ok' => true], $document));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

require_admin_for_write();

$payload = json_input();
$document = milestones_normalize_document(is_array($payload) ? $payload : []);
$encoded = json_encode($document, JSON_UNESCAPED_UNICODE);
if (!is_string($encoded)) {
    json_response(['error' => 'Unable to save milestones timeline'], 500);
}

set_setting('milestones_timeline', $encoded);
json_response(array_merge(['ok' => true], $document));
