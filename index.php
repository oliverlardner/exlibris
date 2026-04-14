<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/layout.php';
require_once __DIR__ . '/lib/formatter.php';

ensure_defaults();
$format = current_citation_format();
$rows = list_sources();
$allProjects = list_projects();
$projectMap = projects_for_source_ids(array_values(array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $rows)));
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
    $search = implode(' ', [
        $source['title'],
        implode(', ', $source['authors']),
        $source['doi'],
        $source['isbn'],
        $source['notes'],
    ]);

    $entries[] = [
        'source' => $source,
        'citation' => $citation,
        'search' => $search,
        'projects' => $projectMap[(int) ($source['id'] ?? 0)] ?? [],
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
        <a class="btn" href="/dump.php">Add Source</a>
    </div>
    <p class="muted">Current citation style: <strong><?= h(strtoupper($format)) ?></strong></p>

    <div class="card">
        <label for="search-input">Search</label>
        <input id="search-input" placeholder="Search title, author, DOI, notes...">
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
        <pre id="semantic-results" class="muted"></pre>
    </div>

    <div class="card stack">
        <h2>Theme Clusters + Digest</h2>
        <div class="actions">
            <button type="button" class="btn btn-copy" id="cluster-btn">Build Clusters</button>
            <button type="button" class="btn btn-copy" id="digest-btn">Run Weekly Digest</button>
            <button type="button" class="btn btn-load" id="zotero-sync-btn">Sync Zotero</button>
            <button type="button" class="btn btn-load" id="zotero-sync-collections-btn">Sync Collections</button>
            <button type="button" class="btn btn-copy" id="zotero-push-unsynced-btn">Push Unsynced</button>
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
            $projects = is_array($entry['projects'] ?? null) ? $entry['projects'] : [];
            $safeUrl = safe_external_url((string) ($source['url'] ?? ''));
            $projectIds = implode(',', array_values(array_filter(array_map(
                static fn (array $project): string => (string) (int) ($project['id'] ?? 0),
                $projects
            ))));
            ?>
            <article
                class="card"
                data-source-card
                data-search="<?= h($search) ?>"
                data-citation="<?= h($citation) ?>"
                data-collection-ids="<?= h($projectIds) ?>"
            >
                <h2><?= h($source['title'] !== '' ? $source['title'] : 'Untitled source') ?></h2>
                <p class="citation"><?= h($citation) ?></p>
                <div class="meta">
                    <span><?= h($source['type']) ?></span>
                    <?php if ($source['year'] !== ''): ?><span><?= h($source['year']) ?></span><?php endif; ?>
                    <?php if ($source['doi'] !== ''): ?><span>DOI: <?= h($source['doi']) ?></span><?php endif; ?>
                    <?php if (($source['origin_provider'] ?? '') === 'zotero'): ?><span class="badge-zotero">Zotero</span><?php endif; ?>
                    <?php foreach ($projects as $project): ?>
                        <span class="badge-collection"><?= h((string) ($project['name'] ?? '')) ?></span>
                    <?php endforeach; ?>
                </div>
                <div class="actions">
                    <a class="btn btn-load" href="/source.php?id=<?= (int) $source['id'] ?>">Load</a>
                    <?php if ($safeUrl !== ''): ?>
                        <a
                            class="btn"
                            href="<?= h($safeUrl) ?>"
                            target="_blank"
                            rel="noopener noreferrer"
                        >Visit</a>
                    <?php endif; ?>
                    <button type="button" class="btn btn-load" data-zotero-push-id="<?= (int) $source['id'] ?>">Push Zotero</button>
                    <button type="button" class="btn btn-cite" data-copy-citation="<?= h($citation) ?>">Cite</button>
                    <button type="button" class="btn btn-danger" data-delete-id="<?= (int) $source['id'] ?>">Delete</button>
                </div>
            </article>
        <?php endforeach; ?>
    </div>

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
