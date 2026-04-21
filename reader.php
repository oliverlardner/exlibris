<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/layout.php';
require_once __DIR__ . '/lib/formatter.php';

ensure_defaults();
$preselectedIds = [];
foreach (explode(',', (string) ($_GET['ids'] ?? '')) as $piece) {
    $id = (int) trim($piece);
    if ($id > 0) {
        $preselectedIds[$id] = true;
    }
}
$context = trim((string) ($_GET['ctx'] ?? ''));
$preselectedSources = [];
foreach (array_keys($preselectedIds) as $id) {
    $row = get_source((int) $id);
    if (!is_array($row)) {
        continue;
    }
    $source = source_to_array($row);
    $preselectedSources[] = [
        'id' => (int) ($source['id'] ?? 0),
        'title' => (string) ($source['title'] ?? ''),
        'authors' => is_array($source['authors'] ?? null) ? array_values(array_map('strval', $source['authors'])) : [],
        'year' => (string) ($source['year'] ?? ''),
    ];
}

render_header('Reader');
?>
<section class="stack">
    <div class="row">
        <h1>L1 Reader</h1>
        <a class="btn btn-secondary" href="/index.php">Back</a>
    </div>
    <p class="muted">Select source(s) and/or write research context, then synthesize a decision-oriented reader brief.</p>

    <article class="card stack">
        <label for="reader-context">Research context</label>
        <textarea id="reader-context" rows="4" placeholder="What are you trying to figure out?"><?= h($context) ?></textarea>
        <div class="actions">
            <button type="button" class="btn btn-load" id="reader-run-btn">Run Reader</button>
            <button type="button" class="btn btn-secondary" id="reader-clear-btn">Clear</button>
        </div>
        <pre id="reader-loader" class="reader-loader hidden" aria-live="polite"></pre>
    </article>

    <article class="card stack">
        <h2>Include Sources</h2>
        <p class="muted">Use semantic search to find and add sources for this reader run.</p>
        <div id="reader-selected-sources" class="stack"></div>
        <input id="reader-source-query" placeholder="Search sources semantically (e.g. VR gender embodiment)" autocomplete="off">
        <div id="reader-source-suggestions" class="stack hidden"></div>
    </article>

    <article id="reader-history-panel" class="card stack">
        <div class="row">
            <h2>Recent Reader Runs</h2>
            <button type="button" class="btn btn-secondary" id="reader-history-refresh-btn">Refresh</button>
        </div>
        <div id="reader-history-list" class="stack"></div>
    </article>

    <article id="reader-results-panel" class="card stack hidden">
        <h2>Reader Synthesis</h2>
        <div id="reader-included-summary" class="stack"></div>
        <pre id="reader-results" class="muted"></pre>
    </article>

    <article id="reader-external-panel" class="card stack hidden">
        <h2>External Candidates</h2>
        <div id="reader-external-list" class="stack"></div>
    </article>

    <article id="reader-trace-panel" class="card stack hidden">
        <h2>Trace</h2>
        <pre id="reader-trace" class="muted"></pre>
    </article>

    <p id="app-status" class="muted"></p>
</section>
<script id="reader-initial-selected" type="application/json"><?= h(json_encode($preselectedSources, JSON_UNESCAPED_UNICODE)) ?></script>
<?php
render_footer();
