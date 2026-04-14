<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/layout.php';

render_header('Cleanup');
?>
<section class="stack">
    <h1>AI Un-fucker</h1>
    <p class="muted">Scan for duplicate sources, review suggested keep/delete decisions, then apply cleanup safely.</p>

    <article class="card stack">
        <div class="row">
            <h2>Duplicate Detection</h2>
            <label><input id="dedupe-use-ai" type="checkbox" checked> Use AI keep suggestions</label>
        </div>
        <div class="actions">
            <button type="button" class="btn btn-load" id="dedupe-scan-btn">Scan Duplicates</button>
        </div>
        <pre id="dedupe-status" class="muted"></pre>
    </article>

    <article class="stack" id="dedupe-groups"></article>
</section>
<?php
render_footer();
