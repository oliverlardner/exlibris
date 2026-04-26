<?php
declare(strict_types=1);

/**
 * Produces the same "plain text" string the browser uses for the viewer
 * (assets/app.js parseViewerMarkdown → plainText). Offsets in that string
 * are what wireAnnotationSurface uses for selections and note ranges.
 */
function viewer_markdown_plain_text(string $raw): string
{
    $doc = viewer_parse_viewer_markdown($raw);

    return $doc['plainText'];
}

/**
 * @return array{blocks: list<array<string, mixed>>, plainText: string}
 */
function viewer_parse_viewer_markdown(string $rawText): array
{
    $normalized = str_replace(["\r\n", "\r"], "\n", $rawText);
    if (!viewer_has_viewer_markdown_syntax($normalized)) {
        $fallback = viewer_create_viewer_markdown_block('viewer-md-block viewer-md-paragraph', $normalized);

        return [
            'blocks' => [$fallback],
            'plainText' => $fallback['text'],
        ];
    }

    $lines = explode("\n", $normalized);
    $blocks = [];
    $paragraphLines = [];
    $codeLines = [];
    $inCodeFence = false;

    $flushParagraph = static function () use (&$blocks, &$paragraphLines): void {
        if ($paragraphLines === []) {
            return;
        }
        $nonEmpty = array_values(
            array_filter(
                array_map(static fn (string $line): string => trim($line), $paragraphLines),
                static fn (string $line): bool => $line !== ''
            )
        );
        $paragraph = $nonEmpty === [] ? '' : implode(' ', $nonEmpty);
        if ($paragraph !== '') {
            $blocks[] = viewer_create_viewer_markdown_block('viewer-md-block viewer-md-paragraph', $paragraph);
        }
        $paragraphLines = [];
    };

    $flushCodeBlock = static function () use (&$blocks, &$codeLines): void {
        $codeText = implode("\n", $codeLines);
        $blocks[] = [
            'className' => 'viewer-md-block viewer-md-code-block',
            'marker' => '',
            'segments' => [['text' => $codeText, 'classes' => ['viewer-md-code-text'], 'href' => '']],
            'text' => $codeText,
        ];
        $codeLines = [];
    };

    foreach ($lines as $line) {
        if (preg_match('/^\s*```/u', $line)) {
            $flushParagraph();
            if ($inCodeFence) {
                $flushCodeBlock();
                $inCodeFence = false;
            } else {
                $inCodeFence = true;
                $codeLines = [];
            }

            continue;
        }

        if ($inCodeFence) {
            $codeLines[] = $line;

            continue;
        }

        if (preg_match('/^\s*$/u', $line)) {
            $flushParagraph();

            continue;
        }

        if (preg_match('/^\s{0,3}(#{1,6})\s+(.*?)\s*#*\s*$/u', $line, $m)) {
            $flushParagraph();
            $level = min(6, strlen($m[1]));
            $blocks[] = viewer_create_viewer_markdown_block(
                'viewer-md-block viewer-md-heading viewer-md-heading-' . $level,
                (string) $m[2]
            );

            continue;
        }

        if (preg_match('/^\s{0,3}>\s?(.*)$/u', $line, $m)) {
            $flushParagraph();
            $blocks[] = viewer_create_viewer_markdown_block('viewer-md-block viewer-md-blockquote', (string) $m[1]);

            continue;
        }

        if (preg_match('/^\s*[-*+]\s+(.*)$/u', $line, $m)) {
            $flushParagraph();
            $blocks[] = viewer_create_viewer_markdown_block(
                'viewer-md-block viewer-md-list-item',
                (string) $m[1],
                ['marker' => '•']
            );

            continue;
        }

        if (preg_match('/^\s*(\d+)\.\s+(.*)$/u', $line, $m)) {
            $flushParagraph();
            $blocks[] = viewer_create_viewer_markdown_block(
                'viewer-md-block viewer-md-list-item viewer-md-ordered-item',
                (string) $m[2],
                ['marker' => $m[1] . '.']
            );

            continue;
        }

        $paragraphLines[] = $line;
    }

    $flushParagraph();
    if ($inCodeFence) {
        $flushCodeBlock();
    }

    $plain = '';
    foreach ($blocks as $i => $block) {
        if ($i > 0) {
            $plain .= "\n";
        }
        $plain .= (string) ($block['text'] ?? '');
    }

    return [
        'blocks' => $blocks,
        'plainText' => $plain,
    ];
}

function viewer_has_viewer_markdown_syntax(string $text): bool
{
    return (bool) preg_match('/(^|\n)\s{0,3}(#{1,6})\s+\S/u', $text)
        || (bool) preg_match('/(^|\n)\s*[-*+]\s+\S/u', $text)
        || (bool) preg_match('/(^|\n)\s*\d+\.\s+\S/u', $text)
        || (bool) preg_match('/(^|\n)\s{0,3}>\s*\S/u', $text)
        || (bool) preg_match('/(^|\n)\s{0,3}```/u', $text)
        || (bool) preg_match('/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/u', $text)
        || (bool) preg_match('/\*\*([^\n]+?)\*\*/u', $text)
        || (bool) preg_match('/`([^`]+)`/u', $text);
}

/**
 * @param array{marker?: string} $options
 * @return array{className: string, marker: string, segments: list<array<string, mixed>>, text: string}
 */
function viewer_create_viewer_markdown_block(string $className, string $text, array $options = []): array
{
    $segments = viewer_parse_inline_markdown_viewer($text, [], '');
    $joined = '';
    foreach ($segments as $seg) {
        $joined .= (string) ($seg['text'] ?? '');
    }

    return [
        'className' => $className,
        'marker' => (string) ($options['marker'] ?? ''),
        'segments' => $segments,
        'text' => $joined,
    ];
}

/**
 * @param list<string> $classes
 * @return list<array{text: string, classes: list<string>, href: string}>
 */
function viewer_parse_inline_markdown_viewer(string $value, array $classes, string $href): array
{
    if ($value === '') {
        return [];
    }

    $candidates = [];

    if (preg_match('/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/u', $value, $m, PREG_OFFSET_CAPTURE)) {
        $candidates[] = [
            'type' => 'link',
            'index' => (int) $m[0][1],
            'full' => (string) $m[0][0],
            'a' => (string) $m[1][0],
            'b' => (string) $m[2][0],
        ];
    }
    if (preg_match('/`([^`]+)`/u', $value, $m, PREG_OFFSET_CAPTURE)) {
        $candidates[] = [
            'type' => 'code',
            'index' => (int) $m[0][1],
            'full' => (string) $m[0][0],
            'a' => (string) $m[1][0],
        ];
    }
    if (preg_match('/\*\*([^\n]+?)\*\*/u', $value, $m, PREG_OFFSET_CAPTURE)) {
        $candidates[] = [
            'type' => 'strong',
            'index' => (int) $m[0][1],
            'full' => (string) $m[0][0],
            'a' => (string) $m[1][0],
        ];
    }

    if ($candidates === []) {
        return [['text' => $value, 'classes' => $classes, 'href' => $href]];
    }

    usort($candidates, static fn (array $a, array $b): int => $a['index'] <=> $b['index']);
    $first = $candidates[0];
    $start = $first['index'];
    $before = substr($value, 0, $start);
    $out = [];
    if ($before !== '') {
        $out[] = ['text' => $before, 'classes' => $classes, 'href' => $href];
    }

    if ($first['type'] === 'link') {
        $inner = viewer_parse_inline_markdown_viewer($first['a'], $classes, $first['b']);
        foreach ($inner as $row) {
            $out[] = $row;
        }
    } elseif ($first['type'] === 'code') {
        $out[] = [
            'text' => $first['a'],
            'classes' => array_merge($classes, ['viewer-md-inline-code']),
            'href' => '',
        ];
    } else { // strong
        $inner = viewer_parse_inline_markdown_viewer(
            $first['a'],
            array_merge($classes, ['viewer-md-strong']),
            $href
        );
        foreach ($inner as $row) {
            $out[] = $row;
        }
    }

    $after = substr($value, $start + strlen($first['full']));
    if ($after !== '') {
        $afterSegs = viewer_parse_inline_markdown_viewer($after, $classes, $href);
        foreach ($afterSegs as $row) {
            $out[] = $row;
        }
    }

    return $out;
}

/**
 * Full reading guide markdown for the on-page viewer: main summary plus
 * structured lists so highlights/notes share one document with the guide body.
 *
 * @param array<string, mixed> $source
 */
function reading_guide_markdown_for_viewer(array $source): string
{
    $summary = (string) ($source['ai_summary'] ?? '');
    $claims = is_array($source['ai_claims'] ?? null) ? $source['ai_claims'] : [];
    $methods = is_array($source['ai_methods'] ?? null) ? $source['ai_methods'] : [];
    $limits = is_array($source['ai_limitations'] ?? null) ? $source['ai_limitations'] : [];

    return ensure_reading_guide_sections_in_summary($summary, $claims, $methods, $limits);
}

/**
 * Return $summary as-is when it already has the standard "## Key claims",
 * "## Methods / approach", "## Limitations" sections. Otherwise append any
 * sections that are missing using the legacy array fields, so older sources
 * (where claims/methods/limitations were stored separately) keep showing the
 * same guide content in one merged document.
 */
function ensure_reading_guide_sections_in_summary(
    string $summary,
    array $claims,
    array $methods,
    array $limitations
): string {
    $summary = (string) $summary;
    $parts = [];
    if (trim($summary) !== '') {
        $parts[] = rtrim($summary);
    }

    $hasSection = static function (string $haystack, string $title): bool {
        $needle = '/(^|\n)\s{0,3}#{2,6}\s+' . preg_quote($title, '/') . '\s*(\n|$)/i';
        return (bool) preg_match($needle, $haystack);
    };

    $appendList = static function (string $title, array $items) use (&$parts, $hasSection): void {
        if ($hasSection(implode('', $parts), $title)) {
            return;
        }
        $clean = [];
        foreach ($items as $item) {
            $s = trim((string) $item);
            if ($s !== '') {
                $clean[] = $s;
            }
        }
        if ($clean === []) {
            return;
        }
        $bullets = [];
        foreach ($clean as $line) {
            $oneLine = preg_replace('/\s+/u', ' ', $line) ?? $line;
            $bullets[] = '- ' . $oneLine;
        }
        $parts[] = "\n\n## {$title}\n\n" . implode("\n\n", $bullets);
    };

    $appendList('Key claims', $claims);
    $appendList('Methods / approach', $methods);
    $appendList('Limitations', $limitations);

    return implode('', $parts);
}

/**
 * Re-align note offsets when the client range does not match the stored plain
 * document (Unicode counting drift, stale offsets after guide edits). Uses
 * quote_text and picks the closest occurrence to the reported start offset.
 *
 * @return array{0: int, 1: int}|null [start, end] in UTF-8 code points, or null
 */
function viewer_align_note_range_to_quote(
    string $documentText,
    int $startOffset,
    int $endOffset,
    string $quoteText,
    string $encoding = 'UTF-8'
): ?array {
    $quote = trim($quoteText);
    if ($quote === '') {
        return null;
    }
    $docLen = mb_strlen($documentText, $encoding);
    if ($docLen <= 0) {
        return null;
    }
    $quoteLen = mb_strlen($quote, $encoding);
    if ($quoteLen <= 0) {
        return null;
    }
    $sliceLen = max(0, $endOffset - $startOffset);
    $slice = $sliceLen > 0 ? mb_substr($documentText, $startOffset, $sliceLen, $encoding) : '';
    if ($slice === $quote) {
        return [$startOffset, $endOffset];
    }
    $positions = [];
    $from = 0;
    while (true) {
        $pos = mb_strpos($documentText, $quote, $from, $encoding);
        if ($pos === false) {
            break;
        }
        $positions[] = (int) $pos;
        $from = $pos + 1;
    }
    if ($positions === []) {
        return null;
    }
    $best = $positions[0];
    $bestDist = abs($best - $startOffset);
    foreach ($positions as $pos) {
        $d = abs($pos - $startOffset);
        if ($d < $bestDist || ($d === $bestDist && $pos < $best)) {
            $best = $pos;
            $bestDist = $d;
        }
    }
    $newEnd = $best + $quoteLen;
    if ($newEnd > $docLen) {
        return null;
    }

    return [$best, $newEnd];
}
