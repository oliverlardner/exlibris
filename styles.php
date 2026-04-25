<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/layout.php';
require_once __DIR__ . '/lib/formatter.php';

ensure_defaults();
render_header('Styles');
?>
<section class="stack">
    <div class="row">
        <h1>Styles</h1>
    </div>
    <p class="muted">
        Reference for Ex Libris UI patterns. When you ask for changes, naming the section here (or the CSS class in <code>assets/style.css</code>) keeps feedback precise.
    </p>

    <nav class="card stack" aria-label="On this page">
        <h2 class="muted" style="margin:0;font-size:1em;">Jump to</h2>
        <div class="actions">
            <a href="#tokens">Tokens</a>
            <a href="#typography">Typography</a>
            <a href="#layout">Layout</a>
            <a href="#buttons">Buttons</a>
            <a href="#forms">Forms</a>
            <a href="#badges-meta">Badges &amp; meta</a>
            <a href="#citation">Citation</a>
            <a href="#semantic">Semantic results</a>
            <a href="#reader">Reader</a>
            <a href="#viewer">Viewer / notes</a>
            <a href="#chrome">Header &amp; nav</a>
            <a href="#status">Status</a>
        </div>
    </nav>

    <article id="tokens" class="card stack">
        <h2>Design tokens <span class="muted">(:root)</span></h2>
        <p class="muted">Semantic colors and surfaces. Theme (header) cycles auto / light / dark.</p>
        <?php
        $tokens = [
            ['--bg', 'Page background'],
            ['--surface', 'Panels, inputs'],
            ['--text', 'Primary text'],
            ['--muted', 'Secondary / help text'],
            ['--accent', 'Links, primary actions'],
            ['--accent-contrast', 'Text on accent fills'],
            ['--success', 'Hover links, positive'],
            ['--danger', 'Errors, destructive'],
            ['--copy', 'Copy / cite actions'],
            ['--load', 'Secondary / load actions'],
            ['--delete', 'Danger button tone'],
        ];
        ?>
        <div class="stack" style="gap:0.5rem;">
            <?php foreach ($tokens as [$var, $note]): ?>
                <div class="row" style="justify-content:flex-start;gap:0.75rem;align-items:center;">
                    <span
                        style="display:inline-block;width:2.75rem;height:1.35rem;background:var(<?= h($var) ?>);border:1px solid color-mix(in srgb, var(--text), transparent 88%);flex-shrink:0;"
                        title="<?= h($var) ?>"
                        aria-hidden="true"
                    ></span>
                    <div>
                        <code><?= h($var) ?></code>
                        <span class="muted"> — <?= h($note) ?></span>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </article>

    <article id="typography" class="card stack">
        <h2>Typography</h2>
        <p class="muted">Body uses IBM Plex Mono (see <code>body</code> in CSS). Headings and buttons use weight 400.</p>
        <h1>Heading 1 <code>&lt;h1&gt;</code></h1>
        <h2>Heading 2 <code>&lt;h2&gt;</code></h2>
        <h3>Heading 3 <code>&lt;h3&gt;</code></h3>
        <p>Body paragraph. <strong>strong</strong> and <b>b</b> match weight 400 in this theme.</p>
        <p class="muted">Paragraph with class <code>.muted</code> — secondary text, help, timestamps.</p>
        <p><code>inline code</code> (use <code>&lt;code&gt;</code>)</p>
        <pre>pre block — .muted color, pre-wrap (trace output, JSON)</pre>
    </article>

    <article id="layout" class="card stack">
        <h2>Layout</h2>
        <p class="muted">Main content is wrapped in <code>&lt;main class="container page"&gt;</code>. Inside pages, vertical rhythm often uses <code>.stack</code>; toolbars use <code>.row</code> or <code>.actions</code>.</p>
        <h3><code>.stack</code></h3>
        <div class="stack" style="outline:1px dashed color-mix(in srgb, var(--accent), transparent 70%);padding:0.5rem;">
            <span>Child A</span>
            <span>Child B</span>
        </div>
        <h3><code>.row</code></h3>
        <div class="row" style="outline:1px dashed color-mix(in srgb, var(--accent), transparent 70%);padding:0.5rem;">
            <span>Left</span>
            <span>Right</span>
        </div>
        <h3><code>.grid</code></h3>
        <div class="grid" style="outline:1px dashed color-mix(in srgb, var(--accent), transparent 70%);padding:0.5rem;">
            <span>Cell 1</span>
            <span>Cell 2</span>
            <span>Cell 3</span>
            <span>Cell 4</span>
        </div>
        <h3><code>.card</code></h3>
        <p class="muted">Surface block: <code>.card</code> (often combined with <code>.stack</code>).</p>
    </article>

    <article id="buttons" class="card stack">
        <h2>Buttons &amp; actions</h2>
        <p class="muted">Buttons use bracket decoration via <code>::before</code> / <code>::after</code> except <code>#theme-toggle</code> (see CSS).</p>
        <div class="actions">
            <button type="button" class="btn">.btn</button>
            <button type="button" class="btn btn-secondary">.btn.btn-secondary</button>
            <button type="button" class="btn btn-load">.btn.btn-load</button>
            <button type="button" class="btn btn-copy">.btn.btn-copy</button>
            <button type="button" class="btn btn-cite">.btn.btn-cite</button>
            <button type="button" class="btn btn-danger">.btn.btn-danger</button>
        </div>
        <p class="muted"><code>.actions</code> — flex row of controls (wrapping).</p>
    </article>

    <article id="forms" class="card stack">
        <h2>Forms</h2>
        <p class="muted"><code>label</code> stacks label text above the control. Inputs inherit <code>var(--surface)</code>.</p>
        <div class="grid">
            <label>Text input
                <input type="text" value="Sample" readonly>
            </label>
            <label>Select
                <select>
                    <option>One</option>
                    <option>Two</option>
                </select>
            </label>
        </div>
        <label>Textarea
            <textarea rows="2" readonly>Multiline</textarea>
        </label>
    </article>

    <article id="badges-meta" class="card stack">
        <h2>Badges &amp; metadata</h2>
        <div class="meta">
            <span class="badge-zotero">.badge-zotero</span>
            <span class="badge-collection">.badge-collection</span>
            <span class="badge-note-tag">.badge-note-tag</span>
        </div>
        <p class="muted"><code>.meta</code> — flex row of muted labels (date, type, etc.).</p>
    </article>

    <article id="citation" class="card stack">
        <h2>Citation</h2>
        <p class="muted">The copy control is an inline <code>.citation-copy-btn</code> at the end of the <code>.citation</code> paragraph (home and source pages).</p>
        <?php render_citation_with_copy('Doe, J. (2020). Example article title. Journal Name, 12(3), 45–67.'); ?>
    </article>

    <article id="semantic" class="card stack">
        <h2>Semantic search results</h2>
        <div class="semantic-results" style="max-width:32rem;">
            <div class="semantic-result-item">
                <a class="semantic-result-title" href="#semantic">.semantic-result-title</a>
                <p class="semantic-result-summary">.semantic-result-summary — muted supporting line.</p>
            </div>
            <div class="semantic-result-item">
                <span class="semantic-result-title" style="cursor:default;">Second item (same .semantic-result-item)</span>
            </div>
        </div>
    </article>

    <article id="reader" class="card stack">
        <h2>Reader UI</h2>
        <p class="muted">Reader page: selected sources, suggestions, synthesis.</p>
        <p>
            <span class="reader-source-tag">#42 Title <button type="button" class="reader-source-tag-remove" title="remove">×</button></span>
            <code>.reader-source-tag</code> <code>.reader-source-tag-remove</code>
        </p>
        <p><button type="button" class="reader-suggestion-title">.reader-suggestion-title</button> (suggestion line)</p>
        <p><a class="reader-source-ref" href="#reader">.reader-source-ref</a> (source id link)</p>
        <div class="reader-run-source-group">
            <h3>.reader-run-source-group</h3>
            <div class="reader-run-source-item">.reader-run-source-item — one row in the list</div>
        </div>
        <pre class="reader-loader" style="min-height:auto;">.reader-loader — ASCII / pre loader</pre>
    </article>

    <article id="viewer" class="card stack">
        <h2>Text viewer &amp; annotations</h2>
        <p class="muted">Classes used on <code>view.php</code> (reading surface + margin notes). Content is often built in JS; samples below are static.</p>
        <p style="font-family:&quot;Helvetica Neue&quot;,Helvetica,Arial,sans-serif;">.viewer-text-panel — sans body text in the reader column</p>
        <p class="viewer-md-heading viewer-md-heading-2">.viewer-md-heading .viewer-md-heading-2</p>
        <blockquote class="viewer-md-blockquote">.viewer-md-blockquote</blockquote>
        <p><span class="viewer-md-strong">.viewer-md-strong</span> · <span class="viewer-md-inline-code">.viewer-md-inline-code</span></p>
        <p><span class="viewer-highlight">.viewer-highlight</span> · <span class="viewer-highlight is-active">.viewer-highlight.is-active</span></p>
        <div class="viewer-note-card" style="position:static;">
            <p class="viewer-note-quote">.viewer-note-quote</p>
            <p>.viewer-note-card (positioned in app)</p>
        </div>
        <div class="viewer-selection-card" style="position:static;margin-top:0.5rem;">.viewer-selection-card</div>
    </article>

    <article id="chrome" class="card stack">
        <h2>Header &amp; navigation</h2>
        <p class="muted">Global header: <code>.site-header</code>, <code>.header-inner</code>, <code>.container</code>. Nav links use bracket styling via <code>.nav a::before/::after</code>.</p>
        <p class="muted">Project chips: <code>.header-projects-control</code>, <code>.header-project-chips</code>, <code>.header-project-chip</code>, <code>.header-project-chip-remove</code>. Citation control: <code>.header-citation-control</code>.</p>
    </article>

    <article id="status" class="card stack">
        <h2>Status &amp; utilities</h2>
        <p class="error">.error — problem message</p>
        <p class="muted">.hidden is on elements that should not display; no demo (would hide).</p>
    </article>
</section>
<?php
render_footer();
