<?php
declare(strict_types=1);

require_once __DIR__ . '/common.php';

function render_header(string $title): void
{
    ensure_defaults();
    $theme = current_theme_mode();
    $format = current_citation_format();
    $projects = list_projects();
    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
    $appBase = $scriptDir === '/' || $scriptDir === '.' ? '' : rtrim($scriptDir, '/');
    ?>
    <!doctype html>
    <html lang="en" data-theme-mode="<?= h($theme) ?>">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?= h($title) ?> | Ex Libris</title>
        <link rel="stylesheet" href="/assets/style.css?v=<?= h(asset_version('style.css')) ?>">
    </head>
    <body data-citation-format="<?= h($format) ?>" data-app-base="<?= h($appBase) ?>">
    <?php $adminToken = trim((string) config_value('security', 'admin_token', '')); if ($adminToken !== ''): ?>
    <script>(function(){var t=<?= json_encode($adminToken) ?>;if(localStorage.getItem('exlibris_admin_token')!==t)localStorage.setItem('exlibris_admin_token',t);})();</script>
    <?php endif; ?>
    <header class="site-header">
        <div class="container header-inner">
            <nav class="nav">
                <a href="/index.php">Home</a>
                <a href="/dump.php">Add</a>
                <details class="nav-more">
                    <summary class="nav-more-summary">[ more... ]</summary>
                    <div class="nav-more-panel">
                        <a href="/cleanup.php">Cleanup</a>
                        <a href="/digest.php">Digest</a>
                        <a href="/styles.php">Styles</a>
                        <a href="/settings.php">Settings</a>
                    </div>
                </details>
            </nav>
            <label class="header-citation-control" for="citation-format">
                <span>Style</span>
                <select id="citation-format" aria-label="Citation style">
                    <?php foreach (supported_citation_formats() as $key => $label): ?>
                        <option value="<?= h($key) ?>" <?= $format === $key ? 'selected' : '' ?>><?= h($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <div class="header-projects-control">
                <span>Current Projects</span>
                <div class="header-projects-editor">
                    <div id="current-project-chips" class="header-project-chips" aria-label="Current projects"></div>
                    <input id="current-project-input" list="current-project-options" placeholder="Add or create project">
                </div>
                <datalist id="current-project-options">
                    <?php foreach ($projects as $project): ?>
                        <option value="<?= h((string) ($project['name'] ?? '')) ?>"></option>
                    <?php endforeach; ?>
                </datalist>
            </div>
            <button id="theme-toggle" class="btn btn-secondary" type="button" title="Toggle theme mode">
                Theme: <?= h(strtoupper($theme)) ?>
            </button>
        </div>
    </header>
    <main class="container page">
    <script id="current-projects-data" type="application/json"><?= json_encode(array_values(array_map(static fn (array $project): array => [
        'id' => (int) ($project['id'] ?? 0),
        'name' => (string) ($project['name'] ?? ''),
    ], $projects)), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?></script>
    <?php
}

function render_footer(): void
{
    ?>
    </main>
    <div id="floating-save-bar" class="floating-save-bar hidden" aria-hidden="true">
        <button type="button" class="btn" id="floating-save-btn">Save changes</button>
    </div>
    <div id="global-ai-activity" class="global-ai-activity hidden" role="status" aria-live="polite" aria-hidden="true" title="AI request in progress">
        <pre id="global-ai-activity-ascii" class="global-ai-activity-ascii"></pre>
    </div>
    <script src="/assets/app.js?v=<?= h(asset_version('app.js')) ?>"></script>
    </body>
    </html>
    <?php
}

/**
 * Returns a short cache-busting token based on the file's mtime (or a fallback
 * stamp when the file can't be read). Causes the browser to automatically pull
 * the new asset after every deploy/edit.
 */
function asset_version(string $filename): string
{
    $path = __DIR__ . '/../assets/' . $filename;
    $mtime = is_file($path) ? filemtime($path) : false;

    return (string) ($mtime !== false ? $mtime : '1');
}
