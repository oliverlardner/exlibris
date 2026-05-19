<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/common.php';
require_once __DIR__ . '/../lib/pomodoro.php';

ensure_defaults();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 50;
    json_response([
        'ok' => true,
        'sessions' => pomo_list_recent($limit),
        'today' => pomo_today_stats(),
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

require_admin_for_write();

$payload = json_input();
$action = strtolower(trim((string) ($payload['action'] ?? '')));

try {
    if ($action === 'start') {
        $type = (string) ($payload['type'] ?? 'work');
        $durationSec = (int) ($payload['duration_sec'] ?? 1500);
        $taskLabel = (string) ($payload['task_label'] ?? '');
        $sourceIdRaw = $payload['source_id'] ?? null;
        $sourceId = null;
        if ($sourceIdRaw !== null && $sourceIdRaw !== '') {
            $sourceId = (int) $sourceIdRaw;
            if ($sourceId <= 0) {
                $sourceId = null;
            }
        }
        $session = pomo_start_session($type, $durationSec, $taskLabel, $sourceId);
        json_response(['ok' => true, 'session' => $session]);
    }

    if ($action === 'complete' || $action === 'abandon') {
        $id = (int) ($payload['id'] ?? 0);
        // Notes are optional — only pass through when the client explicitly
        // sends a value, so an empty-string in a stray call doesn't wipe an
        // autosaved journal.
        $notes = array_key_exists('notes', $payload) ? (string) $payload['notes'] : null;
        $session = pomo_finish_session($id, $action === 'abandon', $notes);
        if ($session === null) {
            // Already-finished or unknown session — return ok with null so
            // the client doesn't treat double-finish as a hard error.
            json_response(['ok' => true, 'session' => null]);
        }
        json_response(['ok' => true, 'session' => $session]);
    }

    if ($action === 'update_notes') {
        $id = (int) ($payload['id'] ?? 0);
        $notes = (string) ($payload['notes'] ?? '');
        $session = pomo_update_notes($id, $notes);
        if ($session === null) {
            json_response(['ok' => true, 'session' => null]);
        }
        json_response(['ok' => true, 'session' => $session]);
    }

    json_response(['ok' => false, 'error' => 'Unknown action. Use start, complete, abandon, or update_notes.'], 422);
} catch (Throwable $e) {
    json_response(['ok' => false, 'error' => $e->getMessage()], 422);
}
