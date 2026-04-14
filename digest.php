<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/layout.php';

ensure_defaults();

$rows = db()->query('SELECT * FROM digest_runs ORDER BY created_at DESC LIMIT 20')->fetchAll() ?: [];

render_header('Weekly Digest');
?>
<section class="stack">
    <div class="row">
        <h1>Weekly Digest</h1>
        <button id="digest-btn" class="btn btn-load" type="button">Run New Digest</button>
    </div>
    <p class="muted">Generated digests are stored for project history and review.</p>
    <pre id="assistant-panel-output" class="muted"></pre>

    <?php foreach ($rows as $row): ?>
        <?php
        $json = $row['digest_json'] ?? '{}';
        if (!is_string($json)) {
            $json = json_encode($json, JSON_UNESCAPED_UNICODE) ?: '{}';
        }
        ?>
        <article class="card stack">
            <h2><?= h((string) ($row['created_at'] ?? '')) ?></h2>
            <p><?= h((string) ($row['digest_text'] ?? '')) ?></p>
            <details>
                <summary>Raw JSON</summary>
                <pre><?= h($json) ?></pre>
            </details>
        </article>
    <?php endforeach; ?>
</section>
<?php
render_footer();
