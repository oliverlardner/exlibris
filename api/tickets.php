<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/common.php';
require_once __DIR__ . '/../lib/tickets.php';

ensure_defaults();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $idRaw = $_GET['id'] ?? null;
    if ($idRaw !== null && trim((string) $idRaw) !== '') {
        $id = (int) $idRaw;
        $ticket = tickets_get($id);
        if ($ticket === null) {
            json_response(['ok' => false, 'error' => 'Ticket not found'], 404);
        }
        json_response(['ok' => true, 'ticket' => $ticket]);
    }

    json_response(['ok' => true, 'tickets' => tickets_list()]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

require_admin_for_write();

$payload = json_input();
$op = strtolower(trim((string) ($payload['op'] ?? '')));

try {
    if ($op === 'suggest') {
        $title = (string) ($payload['title'] ?? '');
        $description = (string) ($payload['description'] ?? '');
        $items = tickets_suggest_subtasks($title, $description);
        json_response(['ok' => true, 'items' => $items]);
    }

    if ($op === 'create') {
        $title = (string) ($payload['title'] ?? '');
        $description = (string) ($payload['description'] ?? '');
        $start = isset($payload['start_date']) ? (string) $payload['start_date'] : '';
        $end = isset($payload['end_date']) ? (string) $payload['end_date'] : '';
        $itemsRaw = $payload['items'] ?? [];
        $items = is_array($itemsRaw) ? $itemsRaw : [];
        $ticket = tickets_create($title, $description, $start, $end, $items);
        json_response(['ok' => true, 'ticket' => $ticket]);
    }

    if ($op === 'update') {
        $id = (int) ($payload['id'] ?? 0);
        $title = (string) ($payload['title'] ?? '');
        $description = (string) ($payload['description'] ?? '');
        $start = isset($payload['start_date']) ? (string) $payload['start_date'] : '';
        $end = isset($payload['end_date']) ? (string) $payload['end_date'] : '';
        $ticket = tickets_update($id, $title, $description, $start, $end);
        json_response(['ok' => true, 'ticket' => $ticket]);
    }

    if ($op === 'delete') {
        $id = (int) ($payload['id'] ?? 0);
        $ok = tickets_delete($id);
        if (!$ok) {
            json_response(['ok' => false, 'error' => 'Ticket not found'], 404);
        }
        json_response(['ok' => true]);
    }

    if ($op === 'patch_item') {
        $itemId = (int) ($payload['item_id'] ?? 0);
        $doneRaw = $payload['done'] ?? false;
        $done = is_bool($doneRaw)
            ? $doneRaw
            : in_array(strtolower((string) $doneRaw), ['1', 'true', 'yes', 'on'], true);
        $ticket = tickets_patch_item_done($itemId, $done);
        if ($ticket === null) {
            json_response(['ok' => false, 'error' => 'Item not found'], 404);
        }
        json_response(['ok' => true, 'ticket' => $ticket]);
    }

    json_response(['ok' => false, 'error' => 'Unknown op. Use suggest, create, update, delete, or patch_item.'], 422);
} catch (Throwable $e) {
    json_response(['ok' => false, 'error' => $e->getMessage()], 422);
}
