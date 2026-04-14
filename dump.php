<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/layout.php';

ensure_defaults();
$projects = list_projects();

render_header('Add Source');
?>
<section class="stack">
    <h1>Add Source Material</h1>
    <p class="muted">
        Paste any URL, DOI, ISBN, or raw source text. Ex Libris will parse and prefill citation metadata.
    </p>

    <div class="card">
        <label for="dump-input">Raw input</label>
        <p class="muted">Tip: drag and drop a `.bib`/`.ris` file or a permalink into this box.</p>
        <textarea id="dump-input" rows="10" placeholder="Paste a URL, DOI, ISBN, or rough notes..."></textarea>
        <div class="actions">
            <button id="process-input" class="btn" type="button">Process</button>
        </div>
    </div>

    <form id="source-form" class="card hidden">
        <h2>Review and Edit</h2>
        <div class="grid">
            <label>Type <input name="type" value="other"></label>
            <label>Title <input name="title"></label>
            <label>Authors (comma-separated) <input name="authors"></label>
            <label>Year <input name="year"></label>
            <label>Publisher <input name="publisher"></label>
            <label>Journal <input name="journal"></label>
            <label>Volume <input name="volume"></label>
            <label>Issue <input name="issue"></label>
            <label>Pages <input name="pages"></label>
            <label>DOI <input name="doi"></label>
            <label>ISBN <input name="isbn"></label>
            <label>URL <input name="url"></label>
            <label>Accessed At <input name="accessed_at" placeholder="2026-04-13T06:50:00+00:00"></label>
            <label>Collections (comma-separated) <input name="project_names" list="project-name-options" placeholder="e.g. Thesis, VR, Theory"></label>
        </div>
        <label>Notes <textarea name="notes" rows="4"></textarea></label>
        <input type="hidden" name="raw_input">
        <input type="hidden" name="id">
        <div class="actions">
            <button id="save-source" class="btn" type="submit">Save Source</button>
        </div>
    </form>

    <p id="dump-status" class="muted"></p>
    <details id="lookup-feedback" class="card hidden">
        <summary>Lookup details</summary>
        <pre id="lookup-feedback-body"></pre>
    </details>
    <article class="card stack">
        <h2>Claim-to-Source Linking</h2>
        <label for="claim-draft">Draft paragraph or claims</label>
        <textarea id="claim-draft" rows="5" placeholder="Paste draft claims, one per line for best results."></textarea>
        <div class="actions">
            <button id="claim-link-btn" class="btn btn-copy" type="button">Link Claims</button>
        </div>
        <pre id="claim-link-results" class="muted"></pre>
    </article>
</section>
<datalist id="project-name-options">
    <?php foreach ($projects as $project): ?>
        <option value="<?= h((string) ($project['name'] ?? '')) ?>"></option>
    <?php endforeach; ?>
</datalist>
<div id="global-dropzone" class="global-dropzone hidden" aria-hidden="true">
    <div class="global-dropzone-inner">
        Drop `.bib`, `.ris`, `.txt`, or a permalink anywhere
    </div>
</div>
<?php
render_footer();
