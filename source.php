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

$aiSummary = trim((string) ($source['ai_summary'] ?? ''));
$aiSummaryRaw = (string) ($source['ai_summary'] ?? '');
$readingGuideNotes = list_source_notes($id, 'reading_guide');
$readingGuideFullMarkdown = reading_guide_markdown_for_viewer($source);
$readingGuideHasContent = trim($readingGuideFullMarkdown) !== '';
$jsonScriptFlags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT;

render_header('Source');
?>
<section class="stack">
    <div class="row">
        <h1>Source Detail</h1>
        <a class="btn btn-secondary" href="/index.php">Back</a>
    </div>

    <article class="card">
        <h2>Citation</h2>
        <?php render_citation_with_copy($citation); ?>
        <div class="meta">
            <?php if (($source['origin_provider'] ?? '') === 'zotero'): ?><span class="badge-zotero">Zotero Imported</span><?php endif; ?>
        </div>
        <label class="stack">
            <span>Collections</span>
            <div class="project-token-field">
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
                    name="project_names"
                    form="source-form"
                    value="<?= h(implode(', ', $projectNames)) ?>"
                >
            </div>
        </label>
        <div class="actions">
            <?php $safeUrl = safe_external_url((string) ($source['url'] ?? '')); ?>
            <?php if ($safeUrl !== ''): ?>
                <a class="btn" href="<?= h($safeUrl) ?>" target="_blank" rel="noopener noreferrer">Visit</a>
            <?php endif; ?>
            <?php if ($bodyText !== ''): ?>
                <a class="btn btn-secondary" href="/view.php?id=<?= (int) $source['id'] ?>">View Text</a>
                <button
                    type="button"
                    class="btn btn-copy"
                    data-body-reformat-id="<?= (int) $source['id'] ?>"
                    data-body-chars="<?= (int) mb_strlen($bodyText) ?>"
                >AI Clean Text</button>
            <?php endif; ?>
            <a class="btn btn-secondary" href="/reader.php?ids=<?= (int) $source['id'] ?>">Reader</a>
            <button type="button" class="btn btn-load" id="zotero-push-source-btn" data-source-id="<?= (int) $source['id'] ?>">Push Zotero</button>
        </div>
    </article>
    <?php
    $readerSnap = is_array($source['reader_synthesis'] ?? null) ? $source['reader_synthesis'] : [];
    $readerSyn = is_array($readerSnap['synthesis'] ?? null) ? $readerSnap['synthesis'] : [];
    $readerUpdated = trim((string) ($readerSnap['updated_at'] ?? ''));
    $readerContextSaved = trim((string) ($readerSnap['research_context'] ?? ''));
    $hasReaderContent = $readerSyn !== [] || $readerContextSaved !== '' || $readerUpdated !== '';
    ?>
    <article class="card stack">
            <h2>Reader synthesis</h2>
            <?php if (!$hasReaderContent): ?>
                <p class="muted">No reader brief stored yet. Open the <a href="/reader.php?ids=<?= (int) $source['id'] ?>">Reader</a> with this source selected and run synthesis to attach notes here.</p>
            <?php else: ?>
            <?php if ($readerContextSaved !== ''): ?>
                <p class="muted"><strong>Research context</strong> (when run): <?= h($readerContextSaved) ?></p>
            <?php else: ?>
                <p class="muted">Last run used <strong>general reading</strong> mode (no research context).</p>
            <?php endif; ?>
            <?php if ($readerUpdated !== ''): ?>
                <p class="muted"><?= h($readerUpdated) ?></p>
            <?php endif; ?>
            <?php if ($readerSyn !== []): ?>
                <?php if (trim((string) ($readerSyn['verdict'] ?? '')) !== ''): ?>
                    <p><strong>Verdict:</strong> <?= h(strtoupper((string) ($readerSyn['verdict'] ?? ''))) ?>
                        <?php if (trim((string) ($readerSyn['verdict_reason'] ?? '')) !== ''): ?>
                            — <?= h((string) $readerSyn['verdict_reason']) ?>
                        <?php endif; ?>
                    </p>
                <?php endif; ?>
                <?php if (trim((string) ($readerSyn['why_now'] ?? '')) !== ''): ?>
                    <p><?= h((string) $readerSyn['why_now']) ?></p>
                <?php endif; ?>
                <?php
                $co = trim((string) ($readerSyn['companion_overview'] ?? ''));
                $cd = trim((string) ($readerSyn['companion_deeper_context'] ?? ''));
                $ct = trim((string) ($readerSyn['companion_reading_tips'] ?? ''));
                ?>
                <?php if ($co !== '' || $cd !== '' || $ct !== ''): ?>
                    <h3>Reading companion</h3>
                    <?php if ($co !== ''): ?>
                        <p><strong>Overview</strong></p>
                        <p><?= nl2br(h($co)) ?></p>
                    <?php endif; ?>
                    <?php if ($cd !== ''): ?>
                        <p><strong>Context &amp; background</strong></p>
                        <p class="muted"><?= nl2br(h($cd)) ?></p>
                    <?php endif; ?>
                    <?php if ($ct !== ''): ?>
                        <p><strong>How to get the most from this source</strong></p>
                        <p><?= nl2br(h($ct)) ?></p>
                    <?php endif; ?>
                <?php endif; ?>
            <?php else: ?>
                <p class="muted">Run the reader from the Reader page with this source selected to generate a brief.</p>
            <?php endif; ?>
            <?php endif; ?>
        </article>
    <article class="card stack source-ai-annotation">
        <h2>AI reading guide</h2>
        <p id="reading-guide-empty-hint" class="muted <?= $readingGuideHasContent ? 'hidden' : '' ?>">
            No guide yet — click <strong>Reading guide</strong> to generate from this source.
        </p>
        <p id="reading-guide-active-hint" class="muted <?= !$readingGuideHasContent ? 'hidden' : '' ?>">
            Highlight anywhere in the guide (including Key claims, Methods, and Limitations at the end) to add margin notes — separate from
            <a href="/view.php?id=<?= (int) $source['id'] ?>">notes on extracted text</a>.
        </p>
        <div class="actions">
            <button type="button" class="btn btn-load" id="assistant-annotate-btn" data-source-id="<?= (int) $source['id'] ?>">Reading guide</button>
            <button type="button" class="btn btn-copy" id="assistant-quality-btn" data-source-id="<?= (int) $source['id'] ?>">Quality</button>
            <button type="button" class="btn btn-copy" id="assistant-citation-qa-btn" data-source-id="<?= (int) $source['id'] ?>">Citation QA</button>
            <button type="button" class="btn btn-copy" id="assistant-similar-btn" data-source-id="<?= (int) $source['id'] ?>">Similar</button>
        </div>
        <div id="assistant-source-output" class="assistant-source-output stack" aria-live="polite"></div>
        <div class="viewer-layout reading-guide-viewer">
            <article class="card stack viewer-main reading-guide-viewer-main">
                <div class="row viewer-toolbar">
                    <h3>Guide</h3>
                    <div class="actions viewer-controls">
                        <p class="muted">Notes: <span id="reading-guide-note-count"><?= h((string) count($readingGuideNotes)) ?></span></p>
                    </div>
                </div>
                <div id="reading-guide-reading-stage" class="viewer-reading-stage">
                    <div
                        id="reading-guide-text-panel"
                        class="viewer-text-panel reading-guide-text-panel"
                        aria-label="AI reading guide"
                    ></div>
                    <div id="reading-guide-annotations-rail" class="viewer-annotations-rail" aria-label="Reading guide notes">
                        <div id="reading-guide-notes-layer" class="viewer-notes-layer"></div>
                        <article id="reading-guide-selection-card" class="viewer-selection-card hidden">
                            <h3>New note on guide</h3>
                            <label>Selected passage
                                <textarea id="reading-guide-selected-quote" rows="4" readonly></textarea>
                            </label>
                            <label>Note
                                <textarea id="reading-guide-note-text" rows="5" placeholder="Why this part matters to you..."></textarea>
                            </label>
                            <label>Tags (comma-separated)
                                <input id="reading-guide-note-tags" placeholder="theme, follow-up, critique">
                            </label>
                            <div class="actions">
                                <button type="button" class="btn btn-load" id="reading-guide-note-save">Save note</button>
                                <button type="button" class="btn btn-secondary" id="reading-guide-note-clear">Clear selection</button>
                            </div>
                        </article>
                    </div>
                </div>
            </article>
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
                <button
                    type="button"
                    class="btn btn-copy"
                    data-body-reformat-id="<?= (int) $source['id'] ?>"
                    data-body-chars="<?= (int) mb_strlen($bodyText) ?>"
                >AI Clean Text</button>
            </div>
        </article>
    <?php endif; ?>

    <article class="card stack source-page-notes">
        <h2 id="source-notes-heading">Notes</h2>
        <p class="muted">General notes on this source (saved with <strong>Edit Source</strong> below).</p>
        <textarea
            id="source-notes-field"
            class="source-notes-textarea"
            name="notes"
            form="source-form"
            rows="16"
            aria-labelledby="source-notes-heading"
            placeholder="Your own notes, reminders, how you plan to use this source…"
        ><?= h($source['notes']) ?></textarea>
    </article>

    <form id="source-form" class="card" data-floating-save>
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
        </div>
        <label>Extracted Text
            <textarea name="body_text" rows="18" placeholder="Paste or replace the extracted text here."><?= h($bodyText) ?></textarea>
        </label>
        <input type="hidden" name="raw_input" value="<?= h($source['raw_input']) ?>">
        <input type="hidden" name="body_source" value="<?= h((string) ($source['body_source'] ?? '')) ?>">
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
    <p id="app-status" class="muted"></p>
</section>
<script id="reading-guide-viewer-data" type="application/json"><?= json_encode([
    'id' => (int) ($source['id'] ?? 0),
    'reading_text' => $readingGuideFullMarkdown,
], $jsonScriptFlags) ?></script>
<script id="reading-guide-notes-data" type="application/json"><?= json_encode($readingGuideNotes, $jsonScriptFlags) ?></script>
<datalist id="project-name-options">
    <?php foreach ($allProjects as $project): ?>
        <option value="<?= h((string) ($project['name'] ?? '')) ?>"></option>
    <?php endforeach; ?>
</datalist>
<?php
render_footer();
