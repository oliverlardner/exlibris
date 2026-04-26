<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/layout.php';
require_once __DIR__ . '/lib/formatter.php';

ensure_defaults();
$format = current_citation_format();
$rows = list_sources();
$allProjects = list_projects();
$sourceIds = array_values(array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $rows));
$projectMap = projects_for_source_ids($sourceIds);
$noteCountMap = source_note_counts_for_source_ids($sourceIds);
$entries = [];

foreach ($rows as $row) {
    $source = source_to_array($row);
    $cache = $row['citation_cache'] ?? [];
    if (is_string($cache)) {
        $cache = json_decode($cache, true);
    }
    if (!is_array($cache)) {
        $cache = [];
    }
    $citation = (string) ($cache[$format] ?? format_citation($source, $format));
    $entryProjects = $projectMap[(int) ($source['id'] ?? 0)] ?? [];
    $projectLabels = array_values(array_filter(array_map(
        static fn (array $project): string => trim((string) ($project['name'] ?? '')),
        $entryProjects
    )));
    $searchBase = implode(' ', array_filter([
        $source['title'],
        implode(', ', $source['authors']),
        $source['doi'],
        $source['isbn'],
        $source['notes'],
    ]));
    $search = trim($searchBase . ' ' . implode(' ', $projectLabels));

    $entries[] = [
        'source' => $source,
        'citation' => $citation,
        'search' => $search,
        'search_base' => $searchBase,
        'projects' => $entryProjects,
        'note_count' => (int) ($noteCountMap[(int) ($source['id'] ?? 0)] ?? 0),
    ];
}

$bibliographyLines = array_values(array_filter(array_map(static function (array $entry): string {
    return trim((string) $entry['citation']);
}, $entries)));
usort($bibliographyLines, static function (string $a, string $b): int {
    return strcasecmp($a, $b);
});
$bibliographyText = implode("\n", $bibliographyLines);

render_header('Bibliography');
?>
<section class="stack">
    <div class="row">
        <h1>Bibliography</h1>
        <div class="actions">
            <a class="btn btn-secondary" id="compose-reader-link" href="/reader.php">Compose Reader</a>
            <a class="btn" href="/dump.php">Add Source</a>
        </div>
    </div>

    <div class="card">
        <label for="search-input">Search</label>
        <input id="search-input" placeholder="Search title, author, DOI, notes, collections…">
        <label for="collection-filter">Filter by collection</label>
        <select id="collection-filter">
            <option value="">All collections</option>
            <?php foreach ($allProjects as $project): ?>
                <option value="<?= (int) ($project['id'] ?? 0) ?>"><?= h((string) ($project['name'] ?? '')) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="card stack">
        <h2>Semantic Search</h2>
        <label for="semantic-query">Natural language query</label>
        <input id="semantic-query" placeholder="Find sources about embodied VR learning">
        <div class="actions">
            <button type="button" class="btn btn-load" id="semantic-search-btn">Search</button>
        </div>
        <div id="semantic-results" class="semantic-results muted"></div>
    </div>

    <div class="card stack">
        <h2>Theme Clusters + Digest</h2>
        <div class="actions">
            <button type="button" class="btn btn-copy" id="cluster-btn">Build Clusters</button>
            <button type="button" class="btn btn-copy" id="digest-btn">Run Weekly Digest</button>
            <button type="button" class="btn btn-load" id="zotero-sync-btn" title="Import items from Zotero into Ex Libris">Pull Zotero</button>
            <button type="button" class="btn btn-load" id="zotero-sync-collections-btn" title="Import Zotero collections as local projects">Pull Collections</button>
            <button type="button" class="btn btn-copy" id="zotero-push-unsynced-btn" title="Push all local sources that aren't yet in Zotero">Push Zotero</button>
        </div>
        <pre id="assistant-panel-output" class="muted"></pre>
    </div>

    <?php if ($rows === []): ?>
        <div class="card">
            <p>No sources yet. Start by adding one from the dump page.</p>
        </div>
    <?php endif; ?>

    <div class="stack">
        <?php foreach ($entries as $entry): ?>
            <?php
            $source = $entry['source'];
            $citation = (string) $entry['citation'];
            $search = (string) $entry['search'];
            $searchBase = (string) ($entry['search_base'] ?? '');
            $projects = is_array($entry['projects'] ?? null) ? $entry['projects'] : [];
            $noteCount = (int) ($entry['note_count'] ?? 0);
            $safeUrl = safe_external_url((string) ($source['url'] ?? ''));
            $hasPdf = trim((string) ($source['pdf_path'] ?? '')) !== '';
            $hasExtractedText = trim((string) ($source['body_text'] ?? '')) !== '';
            $title = $source['title'] !== '' ? $source['title'] : 'Untitled source';
            $titleHref = $hasExtractedText
                ? '/view.php?id=' . (int) ($source['id'] ?? 0)
                : $safeUrl;
            $projectIds = implode(',', array_values(array_filter(array_map(
                static fn (array $project): string => (string) (int) ($project['id'] ?? 0),
                $projects
            ))));
            ?>
            <article
                class="card"
                data-source-card
                data-source-id="<?= (int) ($source['id'] ?? 0) ?>"
                data-search-base="<?= h($searchBase) ?>"
                data-search="<?= h($search) ?>"
                data-citation="<?= h($citation) ?>"
                data-collection-ids="<?= h($projectIds) ?>"
            >
                <h2 class="source-title">
                    <?php if ($titleHref !== ''): ?>
                        <a
                            href="<?= h($titleHref) ?>"
                            <?php if (!$hasExtractedText && $safeUrl !== ''): ?>target="_blank" rel="noopener noreferrer"<?php endif; ?>
                        ><?= h($title) ?></a>
                    <?php else: ?>
                        <?= h($title) ?>
                    <?php endif; ?>
                    <?php if ($hasPdf): ?>
                        <button
                            type="button"
                            class="source-icon-btn pdf-icon-btn"
                            data-pdf-open-id="<?= (int) ($source['id'] ?? 0) ?>"
                            title="Open PDF in Finder"
                            aria-label="Open PDF in Finder"
                        >
                            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                <path d="M7 3h7l5 5v13H7V3zm7 1.5V9h4.5"></path>
                                <path d="M9 13h6M9 16h6M9 19h4"></path>
                            </svg>
                        </button>
                    <?php endif; ?>
                    <?php if ($hasExtractedText): ?>
                        <a
                            class="source-icon-btn text-icon-link"
                            href="/view.php?id=<?= (int) ($source['id'] ?? 0) ?>"
                            title="Open extracted text"
                            aria-label="Open extracted text"
                        >
                            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                <path d="M5 4h14v16H5z"></path>
                                <path d="M8 8h8M8 12h8M8 16h5"></path>
                            </svg>
                        </a>
                    <?php endif; ?>
                    <?php if ($noteCount > 0): ?>
                        <span class="source-note-count" title="<?= h($noteCount === 1 ? '1 note' : ($noteCount . ' notes')) ?>">
                            <?= h('(' . $noteCount . ')') ?>
                        </span>
                    <?php endif; ?>
                </h2>
                <?php render_citation_with_copy($citation); ?>
                <div class="meta">
                    <span><?= h($source['type']) ?></span>
                    <?php if ($source['year'] !== ''): ?><span><?= h($source['year']) ?></span><?php endif; ?>
                    <?php if ($source['doi'] !== ''): ?><span>DOI: <?= h($source['doi']) ?></span><?php endif; ?>
                    <?php if (($source['origin_provider'] ?? '') === 'zotero'): ?><span class="badge-zotero">Zotero</span><?php endif; ?>
                </div>
                <div class="stack source-card-collections">
                    <span>Collections</span>
                    <form
                        id="index-collections-<?= (int) ($source['id'] ?? 0) ?>"
                        class="stack"
                        data-index-source-collections
                    >
                        <div
                            class="project-token-field"
                            data-collections-save-id="<?= (int) ($source['id'] ?? 0) ?>"
                        >
                            <div class="project-token-box header-projects-editor" role="group" aria-label="Collections">
                                <div class="project-token-chips header-project-chips"></div>
                                <input
                                    type="text"
                                    class="project-token-input"
                                    list="project-name-options"
                                    placeholder="Type — match suggestions or add a new tag. Enter or comma to add."
                                    autocomplete="off"
                                >
                            </div>
                            <input
                                type="hidden"
                                class="project-token-hidden"
                                name="collections_snapshot"
                                value="<?= h(implode(', ', array_values(array_filter(array_map(
                                    static fn (array $project): string => trim((string) ($project['name'] ?? '')),
                                    $projects
                                ))))) ?>"
                                autocomplete="off"
                            >
                        </div>
                        <div class="actions">
                            <button type="submit" class="btn btn-secondary index-collections-save">Save collections</button>
                        </div>
                    </form>
                </div>
                <div class="actions">
                    <a class="btn btn-load" href="/source.php?id=<?= (int) $source['id'] ?>">Load</a>
                    <a class="btn btn-secondary" href="/reader.php?ids=<?= (int) $source['id'] ?>">Read</a>
                    <?php if ($safeUrl !== ''): ?>
                        <a
                            class="btn"
                            href="<?= h($safeUrl) ?>"
                            target="_blank"
                            rel="noopener noreferrer"
                        >Visit</a>
                    <?php endif; ?>
                    <button type="button" class="btn btn-load" data-zotero-push-id="<?= (int) $source['id'] ?>">Push Zotero</button>
                    <button type="button" class="btn btn-danger" data-delete-id="<?= (int) $source['id'] ?>">Delete</button>
                </div>
            </article>
        <?php endforeach; ?>
    </div>

    <datalist id="project-name-options">
        <?php foreach ($allProjects as $project): ?>
            <option value="<?= h((string) ($project['name'] ?? '')) ?>"></option>
        <?php endforeach; ?>
    </datalist>

    <p id="app-status" class="muted" role="status" aria-live="polite"></p>

    <article class="card stack">
        <div class="row">
            <h2>Bibliography (Alphabetical)</h2>
            <button type="button" id="bibliography-copy" class="btn btn-copy" data-copy-citation="<?= h($bibliographyText) ?>">Copy All</button>
        </div>
        <textarea id="bibliography-text" rows="14" readonly><?= h($bibliographyText) ?></textarea>
    </article>
</section>
<?php
render_footer();
