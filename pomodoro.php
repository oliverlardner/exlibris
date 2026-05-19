<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/layout.php';
require_once __DIR__ . '/lib/pomodoro.php';

ensure_defaults();

// Lightweight source list for the picker: id + a single human-readable
// label. Keeping this server-rendered means the picker is usable on a
// fresh page load without an extra fetch.
$sourceRows = list_sources();
$sourceOptions = [];
foreach ($sourceRows as $row) {
    if (!is_array($row)) {
        continue;
    }
    $id = (int) ($row['id'] ?? 0);
    if ($id <= 0) {
        continue;
    }
    $title = trim((string) ($row['title'] ?? ''));
    $year = trim((string) ($row['year'] ?? ''));
    $authors = $row['authors'] ?? [];
    if (is_string($authors)) {
        $decoded = json_decode($authors, true);
        $authors = is_array($decoded) ? $decoded : [];
    }
    $firstAuthor = '';
    if (is_array($authors) && $authors !== []) {
        $firstAuthor = trim((string) reset($authors));
    }
    $label = $title !== '' ? $title : ('Source #' . $id);
    $hint = $firstAuthor !== '' || $year !== ''
        ? trim($firstAuthor . ($year !== '' ? ' (' . $year . ')' : ''))
        : '';
    $sourceOptions[] = [
        'id' => $id,
        'label' => $label,
        'hint' => $hint,
    ];
}

$recentSessions = pomo_list_recent(50);
$todayStats = pomo_today_stats();

$jsonFlags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT;

render_header('Pomodoro');
?>
<section class="stack" id="pomodoro-root">
    <div class="row">
        <h1>Pomodoro</h1>
    </div>
    <p class="muted">
        Work in focused intervals: 25 minutes of work, then a 5-minute break. Every fourth pomodoro is followed by a longer 15-minute break. Queue up tasks below — the timer pulls the next task automatically when a work interval ends.
    </p>

    <article class="card stack pomo-timer-card">
        <div class="pomo-dots" id="pomo-dots" aria-label="Cycle progress">
            <span class="pomo-dot" data-index="0"></span>
            <span class="pomo-dot" data-index="1"></span>
            <span class="pomo-dot" data-index="2"></span>
            <span class="pomo-dot" data-index="3"></span>
        </div>
        <div class="pomo-state-row">
            <span class="pomo-state-badge" id="pomo-state-badge">Work</span>
            <span class="muted" id="pomo-cycle-label">Pomodoro 1 of 4</span>
        </div>
        <div class="pomo-timer-display" id="pomo-timer-display" aria-live="polite">25:00</div>
        <div class="pomo-task-label" id="pomo-task-label">No task selected</div>
        <div class="actions pomo-controls">
            <button type="button" class="btn btn-load" id="pomo-start-btn">Start</button>
            <button type="button" class="btn btn-secondary" id="pomo-pause-btn" disabled>Pause</button>
            <button type="button" class="btn btn-secondary" id="pomo-skip-btn">Skip</button>
            <button type="button" class="btn btn-danger" id="pomo-stop-btn" disabled>Stop</button>
        </div>
        <div class="pomo-journal hidden" id="pomo-journal-wrap" aria-hidden="true">
            <label for="pomo-journal" class="pomo-journal-label">
                What are you working on right now?
                <span class="pomo-journal-state muted" id="pomo-journal-state"></span>
            </label>
            <textarea
                id="pomo-journal"
                rows="6"
                placeholder="Notes from this session — what you're doing, what you noticed, what to come back to. Locks when the timer ends."
                spellcheck="true"
            ></textarea>
        </div>
        <details class="pomo-settings">
            <summary>Interval lengths</summary>
            <div class="pomo-settings-grid">
                <label>Work (min)
                    <input type="number" id="pomo-cfg-work" min="1" max="120" step="1" value="25">
                </label>
                <label>Short break (min)
                    <input type="number" id="pomo-cfg-short" min="1" max="60" step="1" value="5">
                </label>
                <label>Long break (min)
                    <input type="number" id="pomo-cfg-long" min="1" max="120" step="1" value="15">
                </label>
                <label>Work intervals before long break
                    <input type="number" id="pomo-cfg-cycle" min="2" max="8" step="1" value="4">
                </label>
            </div>
            <p class="muted">Settings save automatically to this browser.</p>
        </details>
        <p id="pomo-status" class="muted" aria-live="polite"></p>
    </article>

    <article class="card stack pomo-queue-card">
        <h2>Task queue</h2>
        <p class="muted">Add what you want to work on. The current task at the top runs first; when a work interval finishes, the next task in the list slides into the timer.</p>
        <div class="pomo-queue-form">
            <label class="stack">
                <span>Task</span>
                <input type="text" id="pomo-new-task" placeholder="e.g. Outline lit review chapter">
            </label>
            <label class="stack">
                <span>Linked source (optional)</span>
                <input
                    type="text"
                    id="pomo-new-source"
                    list="pomo-source-options"
                    placeholder="Type a title to link a saved source"
                    autocomplete="off"
                >
                <input type="hidden" id="pomo-new-source-id" value="">
            </label>
            <div class="actions">
                <button type="button" class="btn btn-load" id="pomo-add-task">Add to queue</button>
            </div>
        </div>
        <ul id="pomo-queue-list" class="pomo-queue-list" aria-label="Task queue"></ul>
    </article>

    <article class="card stack pomo-history-card">
        <h2>Today &amp; history</h2>
        <p id="pomo-today-summary" class="pomo-today-summary">
            <span><strong id="pomo-today-count"><?= h((string) $todayStats['completed_count']) ?></strong> pomodoros</span>
            <span>·</span>
            <span><strong id="pomo-today-minutes"><?= h((string) $todayStats['focused_minutes']) ?></strong> focused minutes</span>
        </p>
        <h3 class="pomo-history-heading">Recent sessions</h3>
        <ul id="pomo-history-list" class="pomo-history-list" aria-label="Recent pomodoro sessions"></ul>
    </article>

    <p id="app-status" class="muted" aria-live="polite"></p>
</section>

<datalist id="pomo-source-options">
    <?php foreach ($sourceOptions as $opt): ?>
        <option
            value="<?= h($opt['label'] . ($opt['hint'] !== '' ? ' — ' . $opt['hint'] : '')) ?>"
            data-source-id="<?= (int) $opt['id'] ?>"
        ></option>
    <?php endforeach; ?>
</datalist>

<script id="pomo-sources-data" type="application/json"><?= json_encode($sourceOptions, $jsonFlags) ?></script>
<script id="pomo-initial-history" type="application/json"><?= json_encode($recentSessions, $jsonFlags) ?></script>
<script id="pomo-initial-stats" type="application/json"><?= json_encode($todayStats, $jsonFlags) ?></script>

<?php
render_footer();
