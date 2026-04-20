<?php
declare(strict_types=1);

require_once __DIR__ . '/common.php';

function render_header(string $title): void
{
    ensure_defaults();
    $theme = current_theme_mode();
    $format = current_citation_format();
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
                <a href="/cleanup.php">Cleanup</a>
                <a href="/digest.php">Digest</a>
                <a href="/settings.php">Settings</a>
            </nav>
            <button id="theme-toggle" class="btn btn-secondary" type="button" title="Toggle theme mode">
                Theme: <?= h(strtoupper($theme)) ?>
            </button>
        </div>
    </header>
    <main class="container page">
    <?php
}

function render_footer(): void
{
    ?>
    </main>
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
