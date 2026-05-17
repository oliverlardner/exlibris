<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/openai.php';

function tickets_normalize_date(?string $value): ?string
{
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
        return null;
    }

    return $value;
}

function ticket_row_to_public(array $row): array
{
    $start = $row['start_date'] ?? null;
    $end = $row['end_date'] ?? null;

    return [
        'id' => (int) ($row['id'] ?? 0),
        'title' => (string) ($row['title'] ?? ''),
        'description' => (string) ($row['description'] ?? ''),
        'created_at' => (string) ($row['created_at'] ?? ''),
        'start_date' => is_string($start) && $start !== '' ? $start : null,
        'end_date' => is_string($end) && $end !== '' ? $end : null,
        'updated_at' => (string) ($row['updated_at'] ?? ''),
        'items' => [],
    ];
}

function ticket_item_row_to_public(array $row): array
{
    return [
        'id' => (int) ($row['id'] ?? 0),
        'ticket_id' => (int) ($row['ticket_id'] ?? 0),
        'label' => (string) ($row['label'] ?? ''),
        'sort_order' => (int) ($row['sort_order'] ?? 0),
        'done' => (bool) ($row['done'] ?? false),
        'created_at' => (string) ($row['created_at'] ?? ''),
    ];
}

/**
 * @return list<array<string, mixed>>
 */
function tickets_list(): array
{
    $ticketRows = db()->query('SELECT * FROM tickets ORDER BY created_at DESC')->fetchAll() ?: [];
    if ($ticketRows === []) {
        return [];
    }

    $byId = [];
    foreach ($ticketRows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = (int) ($row['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $byId[$id] = ticket_row_to_public($row);
    }

    $ids = array_keys($byId);
    $placeholders = [];
    $params = [];
    foreach ($ids as $i => $tid) {
        $key = 't' . $i;
        $placeholders[] = ':' . $key;
        $params[$key] = $tid;
    }
    $sql = 'SELECT * FROM ticket_items WHERE ticket_id IN (' . implode(', ', $placeholders) . ')
            ORDER BY ticket_id ASC, sort_order ASC, id ASC';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $itemRows = $stmt->fetchAll() ?: [];
    foreach ($itemRows as $itemRow) {
        if (!is_array($itemRow)) {
            continue;
        }
        $ticketId = (int) ($itemRow['ticket_id'] ?? 0);
        if (!isset($byId[$ticketId])) {
            continue;
        }
        $byId[$ticketId]['items'][] = ticket_item_row_to_public($itemRow);
    }

    $out = [];
    foreach ($ids as $tid) {
        if (isset($byId[$tid])) {
            $out[] = $byId[$tid];
        }
    }

    return $out;
}

function tickets_get(int $id): ?array
{
    if ($id <= 0) {
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM tickets WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();
    if (!is_array($row)) {
        return null;
    }
    $ticket = ticket_row_to_public($row);
    $itemsStmt = db()->prepare(
        'SELECT * FROM ticket_items WHERE ticket_id = :ticket_id ORDER BY sort_order ASC, id ASC'
    );
    $itemsStmt->execute(['ticket_id' => $id]);
    $itemRows = $itemsStmt->fetchAll() ?: [];
    foreach ($itemRows as $itemRow) {
        if (is_array($itemRow)) {
            $ticket['items'][] = ticket_item_row_to_public($itemRow);
        }
    }

    return $ticket;
}

/**
 * @param list<string> $labels
 */
function tickets_create(string $title, string $description, ?string $startDate, ?string $endDate, array $labels): array
{
    $title = trim($title);
    if ($title === '') {
        throw new RuntimeException('Title is required.');
    }
    $description = trim($description);
    $start = tickets_normalize_date($startDate);
    $end = tickets_normalize_date($endDate);
    if ($start !== null && $end !== null && $end < $start) {
        throw new RuntimeException('End date cannot be before start date.');
    }

    $cleanLabels = [];
    foreach ($labels as $label) {
        $t = trim((string) $label);
        if ($t !== '') {
            $cleanLabels[] = $t;
        }
    }

    $pdo = db();
    $now = gmdate('c');
    $pdo->beginTransaction();
    try {
        $ins = $pdo->prepare(
            'INSERT INTO tickets (title, description, start_date, end_date, created_at, updated_at)
             VALUES (:title, :description, NULLIF(:start_date, \'\')::date, NULLIF(:end_date, \'\')::date, :created_at, :updated_at)
             RETURNING *'
        );
        $ins->execute([
            'title' => $title,
            'description' => $description,
            'start_date' => $start ?? '',
            'end_date' => $end ?? '',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $ticketRow = $ins->fetch();
        if (!is_array($ticketRow)) {
            throw new RuntimeException('Failed to create ticket.');
        }
        $ticketId = (int) ($ticketRow['id'] ?? 0);
        if ($ticketId <= 0) {
            throw new RuntimeException('Failed to create ticket.');
        }

        $itemInsert = $pdo->prepare(
            'INSERT INTO ticket_items (ticket_id, label, sort_order, done, created_at)
             VALUES (:ticket_id, :label, :sort_order, FALSE, :created_at)'
        );
        foreach ($cleanLabels as $i => $label) {
            $itemInsert->execute([
                'ticket_id' => $ticketId,
                'label' => $label,
                'sort_order' => $i,
                'created_at' => $now,
            ]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    $loaded = tickets_get($ticketId);
    if (!is_array($loaded)) {
        throw new RuntimeException('Failed to load new ticket.');
    }

    return $loaded;
}

function tickets_update(int $id, string $title, string $description, ?string $startDate, ?string $endDate): array
{
    if ($id <= 0) {
        throw new RuntimeException('Invalid ticket id.');
    }
    $existing = tickets_get($id);
    if ($existing === null) {
        throw new RuntimeException('Ticket not found.');
    }

    $title = trim($title);
    if ($title === '') {
        throw new RuntimeException('Title is required.');
    }
    $description = trim($description);
    $start = tickets_normalize_date($startDate);
    $end = tickets_normalize_date($endDate);
    if ($start !== null && $end !== null && $end < $start) {
        throw new RuntimeException('End date cannot be before start date.');
    }

    $now = gmdate('c');
    $stmt = db()->prepare(
        'UPDATE tickets SET
            title = :title,
            description = :description,
            start_date = NULLIF(:start_date, \'\')::date,
            end_date = NULLIF(:end_date, \'\')::date,
            updated_at = :updated_at
         WHERE id = :id'
    );
    $stmt->execute([
        'title' => $title,
        'description' => $description,
        'start_date' => $start ?? '',
        'end_date' => $end ?? '',
        'updated_at' => $now,
        'id' => $id,
    ]);

    $loaded = tickets_get($id);
    if (!is_array($loaded)) {
        throw new RuntimeException('Ticket not found after update.');
    }

    return $loaded;
}

function tickets_delete(int $id): bool
{
    if ($id <= 0) {
        return false;
    }
    $stmt = db()->prepare('DELETE FROM tickets WHERE id = :id');
    $stmt->execute(['id' => $id]);

    return $stmt->rowCount() > 0;
}

function tickets_patch_item_done(int $itemId, bool $done): ?array
{
    if ($itemId <= 0) {
        return null;
    }
    $sel = db()->prepare('SELECT ticket_id FROM ticket_items WHERE id = :id LIMIT 1');
    $sel->execute(['id' => $itemId]);
    $row = $sel->fetch();
    if (!is_array($row)) {
        return null;
    }
    $ticketId = (int) ($row['ticket_id'] ?? 0);
    if ($ticketId <= 0) {
        return null;
    }

    $upd = db()->prepare(
        'UPDATE ticket_items SET done = :done WHERE id = :id RETURNING *'
    );
    $upd->execute(['done' => $done, 'id' => $itemId]);
    $itemRow = $upd->fetch();
    if (!is_array($itemRow)) {
        return null;
    }

    $touch = db()->prepare('UPDATE tickets SET updated_at = :updated_at WHERE id = :id');
    $touch->execute(['updated_at' => gmdate('c'), 'id' => $ticketId]);

    return tickets_get($ticketId);
}

/**
 * @return list<string>
 */
function tickets_suggest_subtasks(string $title, string $description): array
{
    $title = trim($title);
    $description = trim($description);
    if ($title === '' && $description === '') {
        throw new RuntimeException('Enter a title or description to generate sub-tasks.');
    }

    if (effective_openai_api_key() === '') {
        throw new RuntimeException('OpenAI API key is not configured. Add it in Settings first.');
    }

    $user = "Ticket title:\n" . ($title !== '' ? $title : '(none)') . "\n\nDescription:\n" . ($description !== '' ? $description : '(none)');
    $system = 'You break work into concrete sub-tasks. Return only valid JSON with key "items": an array of 4–12 short actionable sub-task strings (no numbering prefixes). ' .
        'Sub-tasks should be specific to the title and description. Use concise imperative phrases (e.g. "Draft outline", "Email supervisor").';

    $decoded = openai_json_response($system, $user, 0.35, 800);
    if (!is_array($decoded)) {
        throw new RuntimeException('AI request failed or returned no JSON.');
    }

    $items = $decoded['items'] ?? null;
    if (!is_array($items)) {
        throw new RuntimeException('AI response missing items array.');
    }

    $out = [];
    foreach ($items as $item) {
        $t = trim((string) $item);
        if ($t !== '' && !in_array($t, $out, true)) {
            $out[] = $t;
        }
        if (count($out) >= 15) {
            break;
        }
    }

    return $out;
}
