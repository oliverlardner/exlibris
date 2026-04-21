<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/layout.php';

ensure_defaults();
$themeMode = current_theme_mode();
$includePages = include_pages_in_citations();
$openAiManagedByEnv = trim((string) getenv('EXLIBRIS_OPENAI_API_KEY')) !== '';
$assistantEnabled = setting('assistant_enabled', '1') !== '0';
$assistantModel = (string) setting('assistant_model', 'gpt-4o-mini');
$zoteroUserId = (string) setting('zotero_user_id', '');
$zoteroApiKeySet = trim((string) setting('zotero_api_key', '')) !== '';
$zoteroLibraryType = (string) setting('zotero_library_type', 'users');
$zoteroLibraryId = (string) setting('zotero_library_id', '');
$zoteroAutoCollectionEnabled = setting('zotero_auto_collection_enabled', '1') !== '0';
$zoteroAutoCollectionName = (string) setting('zotero_auto_collection_name', 'Ex Libris');

render_header('Settings');
?>
<section class="stack">
    <h1>Settings</h1>

    <article class="card stack">
        <h2>Citation Format</h2>
        <p class="muted">Change citation style from the header on any page. Page-number formatting still lives here.</p>
        <label for="include-pages">Include page numbers in formatted citations</label>
        <select id="include-pages">
            <option value="1" <?= $includePages ? 'selected' : '' ?>>Yes</option>
            <option value="0" <?= !$includePages ? 'selected' : '' ?>>No</option>
        </select>
    </article>

    <article class="card stack">
        <h2>Theme</h2>
        <p class="muted">Theme button in the header cycles Auto → Light → Dark.</p>
        <p>Current mode: <strong><?= h(strtoupper($themeMode)) ?></strong></p>
    </article>

    <article class="card stack">
        <h2>OpenAI API Key</h2>
        <p class="muted">Key is tested when you save. Errors show below immediately.</p>
        <?php if ($openAiManagedByEnv): ?>
            <p class="muted">OpenAI key is currently managed by `EXLIBRIS_OPENAI_API_KEY` environment variable.</p>
        <?php endif; ?>
        <form id="openai-key-form" class="stack">
            <label for="openai_api_key">API key</label>
            <input
                id="openai_api_key"
                type="password"
                name="openai_api_key"
                autocomplete="off"
                value=""
                placeholder="<?= $openAiManagedByEnv ? 'Managed by environment variable' : 'sk-...' ?>"
            >
            <div class="actions">
                <button class="btn" type="submit">Save API Key</button>
            </div>
        </form>
        <p id="settings-status" class="muted"></p>
    </article>

    <article class="card stack">
        <h2>Assistant Settings</h2>
        <label for="assistant-enabled">Enable assistant features</label>
        <select id="assistant-enabled">
            <option value="1" <?= $assistantEnabled ? 'selected' : '' ?>>Yes</option>
            <option value="0" <?= !$assistantEnabled ? 'selected' : '' ?>>No</option>
        </select>
        <label for="assistant-model">Assistant model</label>
        <input id="assistant-model" value="<?= h($assistantModel) ?>" placeholder="gpt-4o-mini">
        <div class="actions">
            <button class="btn" id="assistant-settings-save" type="button">Save Assistant Settings</button>
        </div>
    </article>

    <article class="card stack">
        <h2>Zotero Integration</h2>
        <label for="zotero-user-id">Zotero user id</label>
        <input id="zotero-user-id" value="<?= h($zoteroUserId) ?>">
        <label for="zotero-api-key">Zotero API key</label>
        <input id="zotero-api-key" type="password" value="" placeholder="<?= $zoteroApiKeySet ? 'Saved key exists. Enter only to rotate.' : 'Enter Zotero API key' ?>">
        <label for="zotero-library-type">Library type</label>
        <select id="zotero-library-type">
            <option value="users" <?= $zoteroLibraryType === 'users' ? 'selected' : '' ?>>users</option>
            <option value="groups" <?= $zoteroLibraryType === 'groups' ? 'selected' : '' ?>>groups</option>
        </select>
        <label for="zotero-library-id">Library id (for groups)</label>
        <input id="zotero-library-id" value="<?= h($zoteroLibraryId) ?>">
        <label for="zotero-auto-collection-enabled">Auto add pushed items to Zotero collection</label>
        <select id="zotero-auto-collection-enabled">
            <option value="1" <?= $zoteroAutoCollectionEnabled ? 'selected' : '' ?>>Yes</option>
            <option value="0" <?= !$zoteroAutoCollectionEnabled ? 'selected' : '' ?>>No</option>
        </select>
        <label for="zotero-auto-collection-name">Auto collection name</label>
        <input id="zotero-auto-collection-name" value="<?= h($zoteroAutoCollectionName) ?>" placeholder="Ex Libris">
        <div class="actions">
            <button class="btn" id="zotero-settings-save" type="button">Save Zotero Settings</button>
            <button class="btn btn-load" id="zotero-preview-btn" type="button">Preview Import</button>
        </div>
        <pre id="zotero-preview-output" class="muted"></pre>
    </article>
</section>
<?php
render_footer();
