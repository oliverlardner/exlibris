<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/** Allowed interval kinds. Anything else is rejected at the boundary. */
const POMO_SESSION_TYPES = ['work', 'short_break', 'long_break'];

/** Reasonable bounds so a typo in the client can't insert a 0s or 24h session. */
const POMO_MIN_DURATION_SEC = 30;
const POMO_MAX_DURATION_SEC = 4 * 60 * 60;

function pomo_row_to_public(array $row): array
{
    $sourceIdRaw = $row['source_id'] ?? null;
    $sourceId = $sourceIdRaw === null || $sourceIdRaw === '' ? null : (int) $sourceIdRaw;

    return [
        'id' => (int) ($row['id'] ?? 0),
        'task_label' => (string) ($row['task_label'] ?? ''),
        'source_id' => $sourceId,
        'source_title' => (string) ($row['source_title'] ?? ''),
        'type' => (string) ($row['type'] ?? 'work'),
        'duration_sec' => (int) ($row['duration_sec'] ?? 0),
        'completed' => (bool) ($row['completed'] ?? false),
        'abandoned' => (bool) ($row['abandoned'] ?? false),
        'notes' => (string) ($row['notes'] ?? ''),
        'started_at' => (string) ($row['started_at'] ?? ''),
        'ended_at' => (string) ($row['ended_at'] ?? ''),
    ];
}

/**
 * Cap journal length so a runaway client (or paste) can't load megabytes
 * into a single row. ~20 KB of plain text is enough for any plausible
 * pomodoro journaling session.
 */
const POMO_NOTES_MAX_BYTES = 20000;

function pomo_normalize_notes(string $notes): string
{
    // Normalise newlines and strip the lone trailing whitespace the textarea
    // tends to accumulate while typing.
    $notes = str_replace(["\r\n", "\r"], "\n", $notes);
    if (strlen($notes) > POMO_NOTES_MAX_BYTES) {
        $notes = substr($notes, 0, POMO_NOTES_MAX_BYTES);
    }

    return rtrim($notes);
}

function pomo_normalize_type(string $value): string
{
    $value = strtolower(trim($value));
    return in_array($value, POMO_SESSION_TYPES, true) ? $value : 'work';
}

function pomo_normalize_duration(int $seconds): int
{
    if ($seconds < POMO_MIN_DURATION_SEC) {
        return POMO_MIN_DURATION_SEC;
    }
    if ($seconds > POMO_MAX_DURATION_SEC) {
        return POMO_MAX_DURATION_SEC;
    }

    return $seconds;
}

/**
 * @return list<array<string, mixed>>
 */
function pomo_list_recent(int $limit = 50): array
{
    $limit = max(1, min(200, $limit));
    // Left join sources so the history list can show a citation hint without
    // a second round-trip from the client.
    $sql = 'SELECT p.*, COALESCE(s.title, \'\') AS source_title
            FROM pomodoro_sessions p
            LEFT JOIN sources s ON s.id = p.source_id
            ORDER BY p.started_at DESC
            LIMIT :lim';
    $stmt = db()->prepare($sql);
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll() ?: [];
    $out = [];
    foreach ($rows as $row) {
        if (is_array($row)) {
            $out[] = pomo_row_to_public($row);
        }
    }

    return $out;
}

/**
 * Pomodoros completed today (work intervals only) plus total focused minutes.
 * "Today" is the user's local clock — there's no per-user time-zone setting
 * so we use the database server's `current_date` which mirrors what was
 * logged with NOW() when sessions started.
 */
function pomo_today_stats(): array
{
    $sql = 'SELECT
                COUNT(*) FILTER (WHERE completed AND type = \'work\') AS completed_count,
                COALESCE(SUM(duration_sec) FILTER (WHERE completed AND type = \'work\'), 0) AS focused_seconds,
                COUNT(*) FILTER (WHERE abandoned AND type = \'work\') AS abandoned_count
            FROM pomodoro_sessions
            WHERE started_at >= date_trunc(\'day\', NOW())';
    $row = db()->query($sql)->fetch();
    if (!is_array($row)) {
        return ['completed_count' => 0, 'focused_minutes' => 0, 'abandoned_count' => 0];
    }
    $focusedSeconds = (int) ($row['focused_seconds'] ?? 0);

    return [
        'completed_count' => (int) ($row['completed_count'] ?? 0),
        'focused_minutes' => (int) round($focusedSeconds / 60),
        'abandoned_count' => (int) ($row['abandoned_count'] ?? 0),
    ];
}

function pomo_start_session(string $type, int $durationSec, string $taskLabel, ?int $sourceId): array
{
    $type = pomo_normalize_type($type);
    $durationSec = pomo_normalize_duration($durationSec);
    $taskLabel = trim($taskLabel);

    if ($sourceId !== null && $sourceId <= 0) {
        $sourceId = null;
    }
    if ($sourceId !== null) {
        // Validate FK explicitly so we can return a clean error instead of a
        // PDOException leaking out.
        $check = db()->prepare('SELECT id FROM sources WHERE id = :id LIMIT 1');
        $check->execute(['id' => $sourceId]);
        if ($check->fetch() === false) {
            $sourceId = null;
        }
    }

    $stmt = db()->prepare(
        'INSERT INTO pomodoro_sessions
            (task_label, source_id, type, duration_sec, completed, abandoned, notes, started_at)
         VALUES
            (:task_label, :source_id, :type, :duration_sec, FALSE, FALSE, \'\', NOW())
         RETURNING id, task_label, source_id, type, duration_sec, completed, abandoned, notes, started_at, ended_at'
    );
    $stmt->bindValue(':task_label', $taskLabel, PDO::PARAM_STR);
    if ($sourceId === null) {
        $stmt->bindValue(':source_id', null, PDO::PARAM_NULL);
    } else {
        $stmt->bindValue(':source_id', $sourceId, PDO::PARAM_INT);
    }
    $stmt->bindValue(':type', $type, PDO::PARAM_STR);
    $stmt->bindValue(':duration_sec', $durationSec, PDO::PARAM_INT);
    $stmt->execute();
    $row = $stmt->fetch();
    if (!is_array($row)) {
        throw new RuntimeException('Failed to start pomodoro session.');
    }

    return pomo_row_to_public($row);
}

/**
 * Mark a session done. Pass a non-null $notes to overwrite the journal one
 * last time before locking — useful for the client's final "save what's in
 * the textarea right now" call. Pass null to leave whatever was autosaved.
 */
function pomo_finish_session(int $id, bool $abandoned, ?string $notes = null): ?array
{
    if ($id <= 0) {
        return null;
    }

    // Build SQL conditionally so we don't blank out an autosaved note when
    // the client doesn't include `notes` in its complete/abandon call.
    $setNotes = $notes !== null;
    $sql = 'UPDATE pomodoro_sessions
                SET completed = :completed,
                    abandoned = :abandoned,
                    ended_at = NOW()'
        . ($setNotes ? ', notes = :notes' : '') .
            ' WHERE id = :id
              AND ended_at IS NULL
            RETURNING *';

    $stmt = db()->prepare($sql);
    $stmt->bindValue(':completed', $abandoned ? false : true, PDO::PARAM_BOOL);
    $stmt->bindValue(':abandoned', $abandoned, PDO::PARAM_BOOL);
    $stmt->bindValue(':id', $id, PDO::PARAM_INT);
    if ($setNotes) {
        $stmt->bindValue(':notes', pomo_normalize_notes((string) $notes), PDO::PARAM_STR);
    }
    $stmt->execute();
    $row = $stmt->fetch();
    if (!is_array($row)) {
        return null;
    }

    return pomo_row_to_public($row);
}

/**
 * Autosave path: overwrite notes on an in-progress (not-yet-ended) session.
 * Returns null for unknown ids or sessions that have already ended (so the
 * client can't keep writing into a locked row).
 */
function pomo_update_notes(int $id, string $notes): ?array
{
    if ($id <= 0) {
        return null;
    }
    $stmt = db()->prepare(
        'UPDATE pomodoro_sessions
            SET notes = :notes
         WHERE id = :id
           AND ended_at IS NULL
         RETURNING *'
    );
    $stmt->bindValue(':notes', pomo_normalize_notes($notes), PDO::PARAM_STR);
    $stmt->bindValue(':id', $id, PDO::PARAM_INT);
    $stmt->execute();
    $row = $stmt->fetch();
    if (!is_array($row)) {
        return null;
    }

    return pomo_row_to_public($row);
}
