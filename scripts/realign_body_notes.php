<?php
declare(strict_types=1);

/**
 * Re-anchor pre-existing `body` notes that were stored against the raw
 * `body_text` to the viewer-derived plainText, which is what the browser
 * actually uses for selection and highlight rendering. Without this, notes
 * created before the create_source_note fix in lib/db.php highlight the
 * wrong passage on view.php once the server stops aligning to raw body
 * text.
 *
 * Run from the repo root:
 *   php scripts/realign_body_notes.php          # dry run, prints plan
 *   php scripts/realign_body_notes.php --apply  # writes corrected offsets
 */

require_once __DIR__ . '/../lib/common.php';

ensure_defaults();

$apply = in_array('--apply', $argv, true);

$pdo = db();
$rows = $pdo->query(
    "SELECT id, source_id, quote_text, start_offset, end_offset
     FROM source_notes
     WHERE note_scope = 'body'
     ORDER BY id ASC"
)->fetchAll();

$enc = 'UTF-8';
$fixed = 0;
$alreadyOk = 0;
$couldNotAlign = 0;
$noSource = 0;

foreach ($rows as $row) {
    $noteId = (int) $row['id'];
    $sourceId = (int) $row['source_id'];
    $start = max(0, (int) $row['start_offset']);
    $end = max(0, (int) $row['end_offset']);
    $quote = trim((string) $row['quote_text']);

    $source = get_source($sourceId);
    if (!is_array($source)) {
        $noSource++;
        fprintf(STDERR, "note #%d: source #%d missing, skipping\n", $noteId, $sourceId);
        continue;
    }

    $body = (string) ($source['body_text'] ?? '');
    if ($body === '' || $quote === '' || $end <= $start) {
        $noSource++;
        fprintf(STDERR, "note #%d: empty body/quote/range, skipping\n", $noteId);
        continue;
    }

    $plain = viewer_markdown_plain_text($body);
    $plainLen = mb_strlen($plain, $enc);
    $sliceLen = max(0, $end - $start);
    $slice = ($end <= $plainLen && $sliceLen > 0)
        ? mb_substr($plain, $start, $sliceLen, $enc)
        : '';

    if ($slice !== '' && trim($slice) === $quote) {
        $alreadyOk++;
        continue;
    }

    $aligned = viewer_align_note_range_to_quote($plain, $start, $end, $quote, $enc);
    if (!is_array($aligned)) {
        $couldNotAlign++;
        fprintf(
            STDERR,
            "note #%d: could not locate quote in plainText (quote len=%d)\n",
            $noteId,
            mb_strlen($quote, $enc)
        );
        continue;
    }

    [$newStart, $newEnd] = $aligned;
    if ($newStart === $start && $newEnd === $end) {
        $alreadyOk++;
        continue;
    }

    printf(
        "note #%d (source #%d): %d..%d -> %d..%d\n",
        $noteId,
        $sourceId,
        $start,
        $end,
        $newStart,
        $newEnd
    );
    $fixed++;

    if ($apply) {
        $upd = $pdo->prepare(
            'UPDATE source_notes
             SET start_offset = :s, end_offset = :e, updated_at = :u
             WHERE id = :id'
        );
        $upd->execute([
            's' => $newStart,
            'e' => $newEnd,
            'u' => gmdate('c'),
            'id' => $noteId,
        ]);
    }
}

printf(
    "\nSummary: fixed=%d already_ok=%d could_not_align=%d skipped=%d %s\n",
    $fixed,
    $alreadyOk,
    $couldNotAlign,
    $noSource,
    $apply ? '(applied)' : '(dry run; re-run with --apply to persist)'
);
