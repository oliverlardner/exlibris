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
$projects = projects_for_source_ids([(int) ($source['id'] ?? 0)])[(int) ($source['id'] ?? 0)] ?? [];
$projectNames = array_values(array_filter(array_map(static fn (array $project): string => trim((string) ($project['name'] ?? '')), $projects)));
$pdfPath = trim((string) ($source['pdf_path'] ?? ''));
$pdfName = $pdfPath !== '' ? basename($pdfPath) : '';
$bodyText = trim((string) ($source['body_text'] ?? ''));
$bodyChars = strlen($bodyText);
$bodyPreview = $bodyText !== '' ? mb_substr($bodyText, 0, 1200) : '';
$format = current_citation_format();
$cache = $row['citation_cache'] ?? [];
if (is_string($cache)) {
    $cache = json_decode($cache, true);
}
if (!is_array($cache)) {
    $cache = [];
}
$citation = (string) ($cache[$format] ?? format_citation($source, $format));

render_header('Source');
?>
<section class="stack">
    <div class="row">
        <h1>Source Detail</h1>
        <a class="btn btn-secondary" href="/index.php">Back</a>
    </div>

    <article class="card">
        <h2>Current Citation (<?= h(strtoupper($format)) ?>)</h2>
        <p class="citation"><?= h($citation) ?></p>
        <div class="meta">
            <?php if (($source['origin_provider'] ?? '') === 'zotero'): ?><span class="badge-zotero">Zotero Imported</span><?php endif; ?>
            <?php foreach ($projects as $project): ?>
                <span class="badge-collection"><?= h((string) ($project['name'] ?? '')) ?></span>
            <?php endforeach; ?>
        </div>
        <div class="actions">
            <?php $safeUrl = safe_external_url((string) ($source['url'] ?? '')); ?>
            <?php if ($safeUrl !== ''): ?>
                <a class="btn" href="<?= h($safeUrl) ?>" target="_blank" rel="noopener noreferrer">Visit</a>
            <?php endif; ?>
            <?php if ($bodyText !== ''): ?>
                <a class="btn btn-secondary" href="/view.php?id=<?= (int) $source['id'] ?>">View Text</a>
                <button type="button" class="btn btn-copy" data-body-reformat-id="<?= (int) $source['id'] ?>">AI Clean Text</button>
            <?php endif; ?>
            <button type="button" class="btn btn-load" id="zotero-push-source-btn" data-source-id="<?= (int) $source['id'] ?>">Push Zotero</button>
        </div>
    </article>
    <?php if ($pdfPath !== ''): ?>
        <article class="card stack">
            <h2>PDF</h2>
            <p class="muted"><?= h($pdfName) ?></p>
            <?php if ($bodyText !== ''): ?>
                <div class="meta">
                    <span>Extracted</span>
                    <span><?= h(strtoupper((string) ($source['body_source'] !== '' ? $source['body_source'] : 'text'))) ?></span>
                    <span><?= h(number_format($bodyChars)) ?> chars</span>
                    <?php if ((string) ($source['body_fetched_at'] ?? '') !== ''): ?>
                        <span><?= h((string) $source['body_fetched_at']) ?></span>
                    <?php endif; ?>
                </div>
                <pre><?= h($bodyPreview . ($bodyChars > mb_strlen($bodyPreview) ? "\n\n[truncated]" : '')) ?></pre>
            <?php else: ?>
                <p class="muted">No extracted PDF text yet.</p>
            <?php endif; ?>
            <div class="actions">
                <button type="button" class="btn btn-load" id="pdf-open-btn" data-pdf-open-id="<?= (int) $source['id'] ?>">Open in Finder</button>
                <button type="button" class="btn btn-copy" id="pdf-extract-btn" data-pdf-extract-id="<?= (int) $source['id'] ?>">Extract Text</button>
                <?php if ($bodyText !== ''): ?>
                    <a class="btn btn-secondary" href="/view.php?id=<?= (int) $source['id'] ?>">View Text</a>
                <?php endif; ?>
            </div>
        </article>
    <?php endif; ?>

    <?php if ($bodyText !== '' && $pdfPath === ''): ?>
        <article class="card stack">
            <h2>Extracted Text</h2>
            <div class="meta">
                <span><?= h(strtoupper((string) ($source['body_source'] !== '' ? $source['body_source'] : 'text'))) ?></span>
                <span><?= h(number_format($bodyChars)) ?> chars</span>
                <?php if ((string) ($source['body_fetched_at'] ?? '') !== ''): ?>
                    <span><?= h((string) $source['body_fetched_at']) ?></span>
                <?php endif; ?>
            </div>
            <pre><?= h($bodyPreview . ($bodyChars > mb_strlen($bodyPreview) ? "\n\n[truncated]" : '')) ?></pre>
            <div class="actions">
                <a class="btn btn-secondary" href="/view.php?id=<?= (int) $source['id'] ?>">View Text</a>
                <button type="button" class="btn btn-copy" data-body-reformat-id="<?= (int) $source['id'] ?>">AI Clean Text</button>
            </div>
        </article>
    <?php endif; ?>

    <form id="source-form" class="card">
        <h2>Edit Source</h2>
        <div class="grid">
            <label>Type <input name="type" value="<?= h($source['type']) ?>"></label>
            <label>Title <input name="title" value="<?= h($source['title']) ?>"></label>
            <label>Authors (comma-separated)
                <input name="authors" value="<?= h(implode(', ', $source['authors'])) ?>">
            </label>
            <label>Year <input name="year" value="<?= h($source['year']) ?>"></label>
            <label>Publisher <input name="publisher" value="<?= h($source['publisher']) ?>"></label>
            <label>Journal <input name="journal" value="<?= h($source['journal']) ?>"></label>
            <label>Volume <input name="volume" value="<?= h($source['volume']) ?>"></label>
            <label>Issue <input name="issue" value="<?= h($source['issue']) ?>"></label>
            <label>Pages <input name="pages" value="<?= h($source['pages']) ?>"></label>
            <label>DOI <input name="doi" value="<?= h($source['doi']) ?>"></label>
            <label>ISBN <input name="isbn" value="<?= h($source['isbn']) ?>"></label>
            <label>URL <input name="url" value="<?= h($source['url']) ?>"></label>
            <label>Accessed At <input name="accessed_at" value="<?= h($source['accessed_at'] ?? '') ?>"></label>
            <label>Collections (comma-separated) <input name="project_names" list="project-name-options" value="<?= h(implode(', ', $projectNames)) ?>"></label>
        </div>
        <label>Notes <textarea name="notes" rows="4"><?= h($source['notes']) ?></textarea></label>
        <input type="hidden" name="raw_input" value="<?= h($source['raw_input']) ?>">
        <input type="hidden" name="provenance_summary" value="<?= h((string) ($source['provenance_summary'] ?? '')) ?>">
        <input type="hidden" name="lookup_trace_json" value="<?= h(json_encode($source['lookup_trace'] ?? [], JSON_UNESCAPED_UNICODE)) ?>">
        <input type="hidden" name="id" value="<?= (int) $source['id'] ?>">
        <div class="actions">
            <button class="btn" type="submit">Save Changes</button>
        </div>
    </form>
    <?php if (trim((string) ($source['provenance_summary'] ?? '')) !== '' || ($source['lookup_trace'] ?? []) !== []): ?>
        <article class="card stack">
            <h2>Provenance</h2>
            <?php if (trim((string) ($source['provenance_summary'] ?? '')) !== ''): ?>
                <p class="muted"><?= h((string) $source['provenance_summary']) ?></p>
            <?php endif; ?>
            <?php if (($source['lookup_trace'] ?? []) !== []): ?>
                <pre class="muted"><?= h(json_encode($source['lookup_trace'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
            <?php endif; ?>
        </article>
    <?php endif; ?>
    <article class="card stack">
        <h2>Assistant Copilot</h2>
        <p class="muted">Run source-quality scoring, annotation, citation QA, and similar-source lookup.</p>
        <div class="actions">
            <button type="button" class="btn btn-load" id="assistant-annotate-btn" data-source-id="<?= (int) $source['id'] ?>">Annotate</button>
            <button type="button" class="btn btn-copy" id="assistant-quality-btn" data-source-id="<?= (int) $source['id'] ?>">Quality</button>
            <button type="button" class="btn btn-copy" id="assistant-citation-qa-btn" data-source-id="<?= (int) $source['id'] ?>">Citation QA</button>
            <button type="button" class="btn btn-copy" id="assistant-similar-btn" data-source-id="<?= (int) $source['id'] ?>">Similar</button>
        </div>
        <pre id="assistant-source-output" class="muted"></pre>
    </article>
    <p id="app-status" class="muted"></p>
</section>
<datalist id="project-name-options">
    <?php foreach ($allProjects as $project): ?>
        <option value="<?= h((string) ($project['name'] ?? '')) ?>"></option>
    <?php endforeach; ?>
</datalist>
<?php
render_footer();
