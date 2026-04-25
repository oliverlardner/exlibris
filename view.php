<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/layout.php';
require_once __DIR__ . '/lib/formatter.php';

ensure_defaults();
$allProjects = list_projects();

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    header('Location: /index.php');
    exit;
}

$row = get_source($id);
if (!is_array($row)) {
    header('Location: /index.php');
    exit;
}

$source = source_to_array($row);
$bodyText = trim((string) ($source['body_text'] ?? ''));
$notes = list_source_notes((int) ($source['id'] ?? 0));
$projectMap = projects_for_source_ids([(int) ($source['id'] ?? 0)]);
$projects = $projectMap[(int) ($source['id'] ?? 0)] ?? [];
$projectNames = array_values(array_filter(array_map(
    static fn (array $project): string => trim((string) ($project['name'] ?? '')),
    $projects
)));

render_header('View Source');
?>
<section class="stack">
    <div class="row">
        <div class="stack">
            <h1><?= h((string) ($source['title'] !== '' ? $source['title'] : 'Untitled source')) ?></h1>
            <p class="muted">View extracted text, highlight passages, and add tagged notes.</p>
        </div>
        <div class="actions">
            <a class="btn btn-secondary" href="/source.php?id=<?= (int) $source['id'] ?>">Source</a>
            <a class="btn btn-secondary" href="/index.php">Back</a>
        </div>
    </div>

    <article class="card">
        <div class="meta">
            <span><?= h((string) ($source['type'] ?? 'other')) ?></span>
            <?php if ((string) ($source['year'] ?? '') !== ''): ?><span><?= h((string) $source['year']) ?></span><?php endif; ?>
            <?php if ((string) ($source['body_source'] ?? '') !== ''): ?><span><?= h(strtoupper((string) $source['body_source'])) ?></span><?php endif; ?>
            <?php if ((string) ($source['body_fetched_at'] ?? '') !== ''): ?><span><?= h((string) $source['body_fetched_at']) ?></span><?php endif; ?>
        </div>
    </article>

    <article class="card stack">
        <h2>Collections</h2>
        <p class="muted">Inline tags: pick from suggestions or type a new name. × removes. Save to persist.</p>
        <form id="view-collections-form" class="stack" data-floating-save>
            <div
                class="project-token-field"
                id="view-collections-token-field"
                data-collections-save-id="<?= (int) ($source['id'] ?? 0) ?>"
            >
                <div class="project-token-box header-projects-editor" role="group" aria-label="Collections">
                    <div class="project-token-chips header-project-chips"></div>
                    <input
                        type="text"
                        class="project-token-input"
                        list="project-name-options"
                        placeholder="Add collection…"
                        autocomplete="off"
                    >
                </div>
                <input
                    type="hidden"
                    class="project-token-hidden"
                    name="collections_snapshot"
                    value="<?= h(implode(', ', $projectNames)) ?>"
                    autocomplete="off"
                >
            </div>
            <div class="actions">
                <button type="submit" class="btn" id="view-collections-save">Save</button>
            </div>
        </form>
    </article>

    <?php if ($bodyText === ''): ?>
        <article class="card stack">
            <h2>No Extracted Text</h2>
            <p class="muted">Extract text from the source page first, then return here to annotate it.</p>
        </article>
    <?php else: ?>
        <section class="viewer-layout">
            <article class="card stack viewer-main">
                <div class="row viewer-toolbar">
                    <h2>Extracted Text</h2>
                    <div class="actions viewer-controls">
                        <button
                            type="button"
                            class="btn btn-copy"
                            data-body-reformat-id="<?= (int) $source['id'] ?>"
                            data-body-chars="<?= (int) mb_strlen($bodyText) ?>"
                        >AI Clean Text</button>
                        <label for="viewer-font-family">
                            <span>Font</span>
                            <select id="viewer-font-family">
                                <option value="helvetica">Helvetica</option>
                                <option value="arial">Arial</option>
                                <option value="georgia">Georgia</option>
                                <option value="times">Times New Roman</option>
                                <option value="ibm-mono">IBM Mono</option>
                            </select>
                        </label>
                        <label for="viewer-font-size">
                            <span>Size</span>
                            <select id="viewer-font-size">
                                <?php foreach ([10, 11, 12, 13, 14, 16, 18, 20] as $size): ?>
                                    <option value="<?= (int) $size ?>"><?= (int) $size ?>px</option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <p class="muted">Notes: <span id="viewer-note-count"><?= h((string) count($notes)) ?></span></p>
                        <p class="muted"><?= h(number_format(mb_strlen($bodyText))) ?> chars</p>
                    </div>
                </div>
                <div id="viewer-reading-stage" class="viewer-reading-stage">
                    <div id="viewer-text-panel" class="viewer-text-panel" aria-label="Extracted text reader"></div>
                    <div id="viewer-annotations-rail" class="viewer-annotations-rail" aria-label="Annotations">
                        <div id="viewer-notes-layer" class="viewer-notes-layer"></div>
                        <article id="viewer-selection-card" class="viewer-selection-card hidden">
                            <h3>New Note</h3>
                            <label>Selected passage
                                <textarea id="viewer-selected-quote" rows="5" readonly></textarea>
                            </label>
                            <label>Note
                                <textarea id="viewer-note-text" rows="6" placeholder="Why this passage matters..."></textarea>
                            </label>
                            <label>Tags (comma-separated)
                                <input id="viewer-note-tags" placeholder="interface, psychoanalysis, critique">
                            </label>
                            <div class="actions">
                                <button type="button" class="btn btn-load" id="viewer-note-save">Save Note</button>
                                <button type="button" class="btn btn-secondary" id="viewer-note-clear">Clear Selection</button>
                            </div>
                        </article>
                    </div>
                </div>
            </article>
        </section>
    <?php endif; ?>

    <p id="app-status" class="muted"></p>
</section>

<?php $jsonFlags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT; ?>
<script id="viewer-source-data" type="application/json"><?= json_encode([
    'id' => (int) ($source['id'] ?? 0),
    'title' => (string) ($source['title'] ?? ''),
    'body_text' => $bodyText,
], $jsonFlags) ?></script>
<script id="viewer-notes-data" type="application/json"><?= json_encode($notes, $jsonFlags) ?></script>
<datalist id="project-name-options">
    <?php foreach ($allProjects as $project): ?>
        <option value="<?= h((string) ($project['name'] ?? '')) ?>"></option>
    <?php endforeach; ?>
</datalist>
<?php
render_footer();
