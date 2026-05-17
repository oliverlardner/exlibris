<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/layout.php';

ensure_defaults();

render_header('Tickets');
?>
<section class="stack" id="tickets-root">
    <div class="row">
        <h1>Tickets</h1>
    </div>
    <p class="muted">
        Create to-do tickets with optional date range. Use AI to propose sub-tasks from the title and description, remove any you do not want, then save.
    </p>

    <article class="card stack" id="tickets-new-panel">
        <h2>New ticket</h2>
        <label for="ticket-title">Title</label>
        <input id="ticket-title" type="text" placeholder="What are you working toward?" autocomplete="off">

        <label for="ticket-description">Description</label>
        <textarea id="ticket-description" rows="4" placeholder="Context, constraints, success criteria…"></textarea>

        <div class="tickets-date-row">
            <div class="stack">
                <label for="ticket-start">Start date</label>
                <input id="ticket-start" type="date">
            </div>
            <div class="stack">
                <label for="ticket-end">End date</label>
                <input id="ticket-end" type="date">
            </div>
        </div>

        <div class="actions">
            <button type="button" class="btn btn-secondary" id="ticket-suggest-btn">Suggest sub-tasks (AI)</button>
        </div>

        <div id="ticket-suggested-wrap" class="stack hidden">
            <h3 class="tickets-subheading">Sub-tasks to save</h3>
            <p class="muted tickets-hint">Remove lines you do not need. Saving stores the ticket and the remaining sub-tasks.</p>
            <ul id="ticket-suggested-list" class="tickets-suggested-list"></ul>
        </div>

        <div class="actions">
            <button type="button" class="btn" id="ticket-save-btn">Save ticket</button>
        </div>
        <p id="ticket-form-status" class="muted" aria-live="polite"></p>
    </article>

    <article class="card stack">
        <h2>Your tickets</h2>
        <p id="tickets-list-status" class="muted" aria-live="polite"></p>
        <div id="tickets-list" class="stack tickets-list"></div>
    </article>
</section>
<?php
render_footer();
