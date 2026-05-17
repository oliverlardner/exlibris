<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/layout.php';
require_once __DIR__ . '/lib/milestones.php';

ensure_defaults();
$document = milestones_normalize(setting('milestones_timeline'));

render_header('Milestones');
?>
<section class="stack" id="milestones-root">
    <div class="row">
        <h1>Milestones</h1>
    </div>
    <p class="muted">
        Build a timeline by toggling months on or off for each milestone row. Choose the start month and visible span, then save.
    </p>

    <article class="card stack milestones-panel">
        <div class="milestones-controls">
            <label for="milestones-window-start">Start month</label>
            <input id="milestones-window-start" type="month">

            <label for="milestones-window-months">Visible months</label>
            <select id="milestones-window-months">
                <option value="24">24</option>
                <option value="36">36</option>
                <option value="48">48</option>
                <option value="60">60</option>
            </select>

            <button type="button" class="btn btn-load" id="milestones-save-btn">Save</button>
        </div>
        <p id="milestones-status" class="muted" aria-live="polite"></p>
        <p id="milestones-today-note" class="muted" aria-live="polite"></p>

        <div class="milestones-grid-wrap">
            <table class="milestones-grid" id="milestones-grid" aria-label="Milestones timeline">
                <thead id="milestones-grid-head"></thead>
                <tbody id="milestones-grid-body"></tbody>
            </table>
        </div>
    </article>
</section>
<script id="milestones-data" type="application/json"><?= json_encode($document, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?></script>
<?php
render_footer();
