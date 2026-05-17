<?php
declare(strict_types=1);

function milestones_default_rows(): array
{
    return [
        ['id' => 'confirmation', 'section' => 'Milestones', 'label' => 'Confirmation', 'tooltip' => 'Formal institutional review confirming doctoral candidature'],
        ['id' => 'progress_review', 'section' => 'Milestones', 'label' => 'Progress Review', 'tooltip' => 'Mid-candidature review assessing research progress and trajectory'],
        ['id' => 'final_review', 'section' => 'Milestones', 'label' => 'Final Review', 'tooltip' => 'Pre-submission review of completed research and creative work'],
        ['id' => 'thesis_submission', 'section' => 'Milestones', 'label' => 'Thesis Submission', 'tooltip' => 'Submission of final thesis and exegesis for examination'],
        ['id' => 'abstract_100_word', 'section' => 'Milestones', 'label' => '100 Word Abstract', 'tooltip' => '100 word abstract for website library card'],
        ['id' => 'final_exhibition', 'section' => 'Milestones', 'label' => 'Final Exhibition', 'tooltip' => 'Public exhibition or presentation of creative practice work'],
        ['id' => 'ethics_application_1', 'section' => 'Practice', 'label' => 'Ethics Application 1', 'tooltip' => 'Initial ethics application for research involving human participants'],
        ['id' => 'ethics_application_2', 'section' => 'Practice', 'label' => 'Ethics Application 2', 'tooltip' => 'Subsequent or amended ethics application'],
        ['id' => 'archival_research', 'section' => 'Practice', 'label' => 'Archival Research', 'tooltip' => 'Research conducted in archives, libraries and special collections'],
        ['id' => 'fieldwork_phase_1', 'section' => 'Practice', 'label' => 'Fieldwork Phase 1', 'tooltip' => 'First phase of field-based data collection or site visits'],
        ['id' => 'fieldwork_phase_2', 'section' => 'Practice', 'label' => 'Fieldwork Phase 2', 'tooltip' => 'Second phase of field-based data collection or site visits'],
        ['id' => 'studio_creative_development', 'section' => 'Practice', 'label' => 'Studio/Creative Development', 'tooltip' => 'Development and iteration of studio or creative practice work'],
        ['id' => 'introduction', 'section' => 'Exegesis', 'label' => 'Introduction', 'tooltip' => 'Drafting the introduction chapter'],
        ['id' => 'chapter_1', 'section' => 'Exegesis', 'label' => 'Chapter 1', 'tooltip' => 'Drafting Chapter 1'],
        ['id' => 'chapter_2', 'section' => 'Exegesis', 'label' => 'Chapter 2', 'tooltip' => 'Drafting Chapter 2'],
        ['id' => 'chapter_3', 'section' => 'Exegesis', 'label' => 'Chapter 3', 'tooltip' => 'Drafting Chapter 3'],
        ['id' => 'chapter_4', 'section' => 'Exegesis', 'label' => 'Chapter 4', 'tooltip' => 'Drafting Chapter 4'],
        ['id' => 'conclusion', 'section' => 'Exegesis', 'label' => 'Conclusion', 'tooltip' => 'Drafting the conclusion chapter'],
        ['id' => 'final_editing_formatting', 'section' => 'Exegesis', 'label' => 'Final Editing & Formatting', 'tooltip' => 'Copy-editing, proofreading and formatting for submission'],
        ['id' => 'commitment_1', 'section' => 'Personal', 'label' => '[Commitment 1 - e.g. Annual Leave]', 'tooltip' => 'Personal commitment such as annual leave or travel'],
        ['id' => 'commitment_2', 'section' => 'Personal', 'label' => '[Commitment 2 - e.g. Teaching/Work]', 'tooltip' => 'Personal commitment such as teaching or paid work obligations'],
        ['id' => 'commitment_3', 'section' => 'Personal', 'label' => '[Commitment 3 - e.g. Family/Holidays]', 'tooltip' => 'Personal commitment such as family responsibilities or holidays'],
    ];
}

function milestones_default_document(): array
{
    $year = (int) date('Y');

    return [
        'schemaVersion' => 1,
        'windowStart' => sprintf('%04d-01', $year),
        'windowMonths' => 36,
        'rows' => milestones_default_rows(),
        'filled' => new stdClass(),
    ];
}

function milestones_is_valid_month(string $value): bool
{
    return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value) === 1;
}

function milestones_normalize_rows(mixed $rows): array
{
    $defaultRows = milestones_default_rows();
    if (!is_array($rows)) {
        return $defaultRows;
    }

    // Build a lookup of default rows by id for tooltip/row merging.
    $defaultById = [];
    foreach ($defaultRows as $d) {
        $defaultById[$d['id']] = $d;
    }

    $normalized = [];
    $seen = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = trim((string) ($row['id'] ?? ''));
        $section = trim((string) ($row['section'] ?? ''));
        $label = trim((string) ($row['label'] ?? ''));
        if ($id === '' || $section === '' || $label === '' || isset($seen[$id])) {
            continue;
        }
        $seen[$id] = true;
        $entry = [
            'id' => $id,
            'section' => $section,
            'label' => $label,
        ];
        // Prefer an explicit tooltip on the saved row; fall back to the default.
        $tooltip = trim((string) ($row['tooltip'] ?? ''));
        if ($tooltip === '' && isset($defaultById[$id]['tooltip'])) {
            $tooltip = $defaultById[$id]['tooltip'];
        }
        if ($tooltip !== '') {
            $entry['tooltip'] = $tooltip;
        }
        $normalized[] = $entry;
    }

    if ($normalized === []) {
        return $defaultRows;
    }

    // Append any new default rows that are absent from the saved set,
    // inserting them after the last row that shares the same section (or at the end).
    foreach ($defaultRows as $default) {
        if (isset($seen[$default['id']])) {
            continue;
        }
        $insertAfter = -1;
        foreach ($normalized as $i => $n) {
            if ($n['section'] === $default['section']) {
                $insertAfter = $i;
            }
        }
        if ($insertAfter >= 0) {
            array_splice($normalized, $insertAfter + 1, 0, [$default]);
        } else {
            $normalized[] = $default;
        }
        $seen[$default['id']] = true;
    }

    return $normalized;
}

function milestones_normalize_filled(mixed $filled, array $rows): array
{
    if (!is_array($filled)) {
        return [];
    }

    $allowedIds = [];
    foreach ($rows as $row) {
        if (is_array($row) && is_string($row['id'] ?? null)) {
            $allowedIds[$row['id']] = true;
        }
    }

    $normalized = [];
    foreach ($filled as $rowId => $months) {
        if (!is_string($rowId) || !isset($allowedIds[$rowId]) || !is_array($months)) {
            continue;
        }
        $monthSet = [];
        foreach ($months as $month) {
            if (is_string($month) && milestones_is_valid_month($month)) {
                $monthSet[$month] = true;
            }
        }
        if ($monthSet === []) {
            continue;
        }
        $items = array_keys($monthSet);
        sort($items, SORT_STRING);
        $normalized[$rowId] = $items;
    }

    return $normalized;
}

function milestones_normalize_document(array $document): array
{
    $defaults = milestones_default_document();

    $windowStart = trim((string) ($document['windowStart'] ?? ''));
    if (!milestones_is_valid_month($windowStart)) {
        $windowStart = (string) $defaults['windowStart'];
    }

    $windowMonthsRaw = $document['windowMonths'] ?? $defaults['windowMonths'];
    $windowMonths = (int) $windowMonthsRaw;
    if ($windowMonths <= 0 && is_string($windowMonthsRaw) && is_numeric($windowMonthsRaw)) {
        $windowMonths = (int) $windowMonthsRaw;
    }
    if ($windowMonths < 12) {
        $windowMonths = 12;
    }
    if ($windowMonths > 60) {
        $windowMonths = 60;
    }

    $rows = milestones_normalize_rows($document['rows'] ?? []);
    $filled = milestones_normalize_filled($document['filled'] ?? [], $rows);

    return [
        'schemaVersion' => 1,
        'windowStart' => $windowStart,
        'windowMonths' => $windowMonths,
        'rows' => $rows,
        'filled' => $filled,
    ];
}

function milestones_normalize(?string $raw): array
{
    if (!is_string($raw) || trim($raw) === '') {
        return milestones_default_document();
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return milestones_default_document();
    }

    return milestones_normalize_document($decoded);
}
