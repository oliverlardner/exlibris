<?php
declare(strict_types=1);

/**
 * Extract a TeX-source archive (the kind arXiv ships under "Other formats"
 * — either a `.zip` or, more commonly, a `.tar.gz` / `.tgz`) into one
 * consolidated, reader-friendly plain text blob that the viewer
 * highlight/notes machinery can map character offsets onto.
 *
 * No external tools are required — zips go through bundled ZipArchive,
 * tar variants through bundled PharData, and a conservative regex-based
 * LaTeX → plain text conversion runs on the inlined result. The output
 * keeps heading hashes (`#`, `##`, …) and list markers (`- `, `1.`) so it
 * reads cleanly in the existing markdown-ish viewer.
 */

/**
 * Hard caps so a runaway upload can't fill the disk or wedge PHP. ArXiv
 * source tarballs are typically <2 MB, but generous totals keep monographs
 * and bundles with figures workable. Per-file caps stop a single TeX file
 * from blowing past memory limits.
 */
const TEX_ARCHIVE_MAX_FILES = 4000;
const TEX_ARCHIVE_MAX_TOTAL_BYTES = 80 * 1024 * 1024;
const TEX_FILE_MAX_BYTES = 8 * 1024 * 1024;
const TEX_IMAGE_MAX_BYTES = 24 * 1024 * 1024;

// Back-compat constants — earlier revisions of this file only accepted zips.
const TEX_ZIP_MAX_FILES = TEX_ARCHIVE_MAX_FILES;
const TEX_ZIP_MAX_TOTAL_BYTES = TEX_ARCHIVE_MAX_TOTAL_BYTES;

/**
 * Browser-displayable image extensions; everything else (pdf, eps, …) is
 * still extracted but rendered as a plain link in the body text.
 */
const TEX_IMAGE_INLINE_EXTS = ['png', 'jpg', 'jpeg', 'gif', 'svg', 'webp', 'avif'];
const TEX_IMAGE_LINK_EXTS = ['pdf', 'eps', 'ps'];

/**
 * Inspect the file header and decide what archive format we have. Returns
 * one of: 'zip', 'tar', 'tar.gz', 'tar.bz2', or '' when nothing matched.
 * We never trust the upload's extension alone — Safari/Finder routinely
 * drop or rewrite it on drag-and-drop.
 */
function tex_detect_archive_format(string $path): string
{
    $fh = @fopen($path, 'rb');
    if (!is_resource($fh)) {
        return '';
    }
    try {
        $head = (string) fread($fh, 4);
        if ($head === '') {
            return '';
        }
        if (str_starts_with($head, "PK\x03\x04") || str_starts_with($head, "PK\x05\x06")) {
            return 'zip';
        }
        if (str_starts_with($head, "\x1f\x8b")) {
            return 'tar.gz';
        }
        if (str_starts_with($head, 'BZh')) {
            return 'tar.bz2';
        }
        // Uncompressed tar has "ustar" at byte 257 (POSIX) — sniff a wider
        // window so both pre- and post-1988 tar variants resolve cleanly.
        fseek($fh, 257);
        $marker = (string) fread($fh, 8);
        if (str_starts_with($marker, 'ustar')) {
            return 'tar';
        }
    } finally {
        fclose($fh);
    }

    return '';
}

/**
 * Top-level entry point. Returns:
 *   [
 *     'body_text'    => string consolidated plain text (with markdown image
 *                       / link references where the source referenced
 *                       \includegraphics),
 *     'main_file'    => string relative path of the chosen root .tex,
 *     'files'        => list<string> all .tex files seen (relative paths),
 *     'tex_source'   => string concatenated (and \input-inlined) raw TeX,
 *     'format'       => string detected archive format,
 *     'media'        => list<array{name,url,inline,archive_path,bytes}>
 *                       images that were staged for serving (empty when
 *                       $options['media_dir'] is null),
 *     'change_summary' => string brief human note,
 *   ]
 *
 * $options:
 *   - 'media_dir' (string|null): absolute filesystem dir to stage referenced
 *     image files into. When null (or empty), figures are stripped and no
 *     image markdown is emitted.
 *   - 'media_url_base' (string): URL prefix to compose <img>/<a> targets,
 *     e.g. '/api/media.php?id=42&file='. The staged file basename is
 *     URL-encoded and appended.
 *
 * Throws RuntimeException with a user-readable message on failure.
 */
function tex_archive_extract_to_text(string $archivePath, array $options = []): array
{
    if (!is_file($archivePath)) {
        throw new RuntimeException('Uploaded archive not found on disk.');
    }

    $format = tex_detect_archive_format($archivePath);
    if ($format === '') {
        throw new RuntimeException('Unsupported archive format. Expected .zip, .tar, .tar.gz, or .tar.bz2.');
    }

    $collected = $format === 'zip'
        ? tex_collect_from_zip($archivePath)
        : tex_collect_from_tar($archivePath, $format);

    $texFiles = $collected['tex'];
    $mediaBlobs = $collected['media'];

    if ($texFiles === []) {
        throw new RuntimeException('No .tex files found inside the archive.');
    }

    $mainFile = tex_pick_main_file($texFiles);
    $inlined = tex_inline_inputs($texFiles[$mainFile], $mainFile, $texFiles);

    $mediaDir = isset($options['media_dir']) ? (string) $options['media_dir'] : '';
    $mediaUrlBase = isset($options['media_url_base']) ? (string) $options['media_url_base'] : '';

    $stagedMedia = [];
    if ($mediaDir !== '' && $mediaBlobs !== []) {
        // Substitute figure environments and bare \includegraphics with
        // markdown image/link tokens before the LaTeX → plain text pass.
        // The plain-text converter is intentionally permissive and won't
        // mangle markdown syntax that survives this stage.
        $inlined = tex_substitute_figures($inlined, $mainFile, $mediaBlobs, $mediaDir, $mediaUrlBase, $stagedMedia);
    }

    $plain = tex_to_plain_text($inlined);

    $changeSummary = sprintf(
        'Parsed %d .tex file(s) from %s, root %s, produced %s chars%s.',
        count($texFiles),
        $format,
        basename($mainFile),
        number_format(mb_strlen($plain)),
        $stagedMedia !== [] ? ', ' . count($stagedMedia) . ' image(s) staged' : ''
    );

    return [
        'body_text' => $plain,
        'main_file' => $mainFile,
        'files' => array_keys($texFiles),
        'tex_source' => $inlined,
        'format' => $format,
        'media' => array_values($stagedMedia),
        'change_summary' => $changeSummary,
    ];
}

/**
 * Back-compat alias — earlier callers used a zip-only name.
 */
function tex_zip_extract_to_text(string $archivePath, array $options = []): array
{
    return tex_archive_extract_to_text($archivePath, $options);
}

/**
 * Walk a zip archive and return:
 *   [
 *     'tex'   => array<string,string>  // relPath => decoded .tex source
 *     'media' => array<string,string>  // relPath => raw image bytes
 *   ]
 *
 * Non-text image content is kept in memory because most TeX source bundles
 * are small (<2 MB); the per-file cap (TEX_IMAGE_MAX_BYTES) and total cap
 * (TEX_ARCHIVE_MAX_TOTAL_BYTES) protect against the rare exception.
 *
 * @return array{tex: array<string,string>, media: array<string,string>}
 */
function tex_collect_from_zip(string $zipPath): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('PHP ZipArchive extension is not available.');
    }
    $zip = new ZipArchive();
    $openCode = $zip->open($zipPath, ZipArchive::RDONLY);
    if ($openCode !== true) {
        throw new RuntimeException('Could not open zip archive (code ' . (string) $openCode . ').');
    }
    try {
        if ($zip->numFiles > TEX_ARCHIVE_MAX_FILES) {
            throw new RuntimeException('Zip contains too many entries (' . (string) $zip->numFiles . ').');
        }
        $texFiles = [];
        $mediaFiles = [];
        $totalBytes = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if (!is_array($stat)) {
                continue;
            }
            $name = (string) ($stat['name'] ?? '');
            if ($name === '' || str_ends_with($name, '/') || str_contains($name, '..')) {
                continue;
            }
            $size = (int) ($stat['size'] ?? 0);
            $totalBytes += $size;
            if ($totalBytes > TEX_ARCHIVE_MAX_TOTAL_BYTES) {
                throw new RuntimeException('Zip uncompresses to more than the allowed limit.');
            }
            if (tex_is_tex_filename($name)) {
                if ($size > TEX_FILE_MAX_BYTES) {
                    continue;
                }
                $raw = $zip->getFromIndex($i);
                if (!is_string($raw)) {
                    continue;
                }
                $texFiles[$name] = tex_normalise_encoding($raw);
            } elseif (tex_is_media_filename($name)) {
                if ($size > TEX_IMAGE_MAX_BYTES) {
                    continue;
                }
                $raw = $zip->getFromIndex($i);
                if (!is_string($raw)) {
                    continue;
                }
                $mediaFiles[$name] = $raw;
            }
        }

        return ['tex' => $texFiles, 'media' => $mediaFiles];
    } finally {
        $zip->close();
    }
}

/**
 * Walk a tar / tar.gz / tar.bz2 archive via PharData. PharData picks the
 * decompression strategy from the file extension, so callers must hand us
 * an archive path whose suffix matches the detected $format.
 *
 * @return array{tex: array<string,string>, media: array<string,string>}
 */
function tex_collect_from_tar(string $tarPath, string $format): array
{
    if (!class_exists('PharData')) {
        throw new RuntimeException('PHP Phar extension is not available — cannot read tar archives.');
    }
    // PharData refuses to read a file whose extension does not advertise the
    // compression scheme it actually uses. Drag-and-drop uploads frequently
    // lose extensions, so stage a correctly-named hardlink (fall back to a
    // copy when hardlinks aren't possible — e.g. across filesystems).
    $suffix = match ($format) {
        'tar' => '.tar',
        'tar.gz' => '.tar.gz',
        'tar.bz2' => '.tar.bz2',
        default => '',
    };
    if ($suffix === '') {
        throw new RuntimeException('Unsupported tar variant: ' . $format);
    }

    // PharData maintains a process-wide map of opened archives keyed by
    // resolved path. To stay robust against reuse (and to guarantee the
    // file name advertises the compression scheme), always stage the input
    // to a fresh per-call path with the correct suffix.
    $stagedPath = (string) tempnam(sys_get_temp_dir(), 'exlibris-tex-');
    @unlink($stagedPath);
    $stagedPath .= $suffix;
    if (!@copy($tarPath, $stagedPath)) {
        throw new RuntimeException('Could not stage tar archive for reading.');
    }
    $usedStaging = true;

    try {
        $phar = new PharData($stagedPath);
    } catch (Throwable $e) {
        if ($usedStaging) {
            @unlink($stagedPath);
        }
        throw new RuntimeException('Could not open tar archive: ' . $e->getMessage());
    }

    // PharData uses realpath() internally before composing the phar:// URL,
    // so on macOS a staged file under /tmp/... is exposed as
    // phar:///private/tmp/.../archive.tar.gz/... — use realpath() for
    // matching, but fall back to the original path so first-pass matching
    // still works on systems where realpath() resolves to itself.
    $real = realpath($stagedPath);
    $prefixCandidates = array_unique(array_filter([
        is_string($real) && $real !== '' ? 'phar://' . $real . '/' : null,
        'phar://' . $stagedPath . '/',
    ]));

    $texFiles = [];
    $mediaFiles = [];
    $totalBytes = 0;
    $seenCount = 0;
    try {
        $iter = new RecursiveIteratorIterator($phar, RecursiveIteratorIterator::LEAVES_ONLY);
        foreach ($iter as $entry) {
            /** @var PharFileInfo $entry */
            $seenCount += 1;
            if ($seenCount > TEX_ARCHIVE_MAX_FILES) {
                throw new RuntimeException('Tar contains too many entries (>' . TEX_ARCHIVE_MAX_FILES . ').');
            }
            if (!$entry->isFile()) {
                continue;
            }
            // PharFileInfo::getPathname() returns the phar:// URL; trim it
            // back to a clean archive-relative path so downstream lookups
            // (e.g. \input resolution) match the names users see.
            $full = (string) $entry->getPathname();
            $rel = '';
            foreach ($prefixCandidates as $prefix) {
                if (str_starts_with($full, (string) $prefix)) {
                    $rel = substr($full, strlen((string) $prefix));
                    break;
                }
            }
            if ($rel === '') {
                // Last-resort: chop everything up to the first "/archive-basename/"
                // segment so deeply nested entries still keep their subdirs.
                $base = basename($stagedPath);
                $needle = '/' . $base . '/';
                $idx = strpos($full, $needle);
                if ($idx !== false) {
                    $rel = substr($full, $idx + strlen($needle));
                } else {
                    $rel = (string) $entry->getFilename();
                }
            }
            if ($rel === '' || str_contains($rel, '..')) {
                continue;
            }
            $size = (int) $entry->getSize();
            $totalBytes += $size;
            if ($totalBytes > TEX_ARCHIVE_MAX_TOTAL_BYTES) {
                throw new RuntimeException('Tar uncompresses to more than the allowed limit.');
            }
            if (tex_is_tex_filename($rel)) {
                if ($size > TEX_FILE_MAX_BYTES) {
                    continue;
                }
                $raw = @file_get_contents($full);
                if (!is_string($raw)) {
                    continue;
                }
                $texFiles[$rel] = tex_normalise_encoding($raw);
            } elseif (tex_is_media_filename($rel)) {
                if ($size > TEX_IMAGE_MAX_BYTES) {
                    continue;
                }
                $raw = @file_get_contents($full);
                if (!is_string($raw)) {
                    continue;
                }
                $mediaFiles[$rel] = $raw;
            }
        }
    } finally {
        if ($usedStaging) {
            @unlink($stagedPath);
        }
    }

    return ['tex' => $texFiles, 'media' => $mediaFiles];
}

function tex_is_tex_filename(string $name): bool
{
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

    return in_array($ext, ['tex', 'ltx'], true);
}

function tex_is_media_filename(string $name): bool
{
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

    return in_array($ext, array_merge(TEX_IMAGE_INLINE_EXTS, TEX_IMAGE_LINK_EXTS), true);
}

/**
 * Best-effort UTF-8 normalisation. Most arXiv tarballs are already UTF-8 or
 * Latin-1, occasionally with a BOM; never trust the encoding blindly.
 */
function tex_normalise_encoding(string $raw): string
{
    if ($raw === '') {
        return '';
    }
    if (substr($raw, 0, 3) === "\xEF\xBB\xBF") {
        $raw = substr($raw, 3);
    }
    if (!mb_check_encoding($raw, 'UTF-8')) {
        $converted = @mb_convert_encoding($raw, 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252');
        if (is_string($converted) && $converted !== '') {
            $raw = $converted;
        }
    }

    return str_replace(["\r\n", "\r"], "\n", $raw);
}

/**
 * Pick the root .tex file. Preference order:
 *   1. A file containing \documentclass — most reliable.
 *   2. Files named main.tex / paper.tex / manuscript.tex / ms.tex (case-insensitive).
 *   3. A file containing \begin{document}.
 *   4. The largest .tex file.
 *
 * @param array<string,string> $texFiles relPath => source
 */
function tex_pick_main_file(array $texFiles): string
{
    $candidates = [];
    foreach ($texFiles as $path => $text) {
        $hasDocclass = preg_match('/\\\\documentclass\b/', $text) === 1;
        $hasBeginDoc = preg_match('/\\\\begin\{document\}/', $text) === 1;
        $base = strtolower(basename($path));
        $isPreferredName = in_array($base, ['main.tex', 'paper.tex', 'manuscript.tex', 'ms.tex', 'arxiv.tex'], true);
        $candidates[$path] = [
            'has_docclass' => $hasDocclass,
            'has_begindoc' => $hasBeginDoc,
            'preferred_name' => $isPreferredName,
            'len' => strlen($text),
        ];
    }
    uasort($candidates, static function (array $a, array $b): int {
        if ($a['has_docclass'] !== $b['has_docclass']) {
            return $a['has_docclass'] ? -1 : 1;
        }
        if ($a['preferred_name'] !== $b['preferred_name']) {
            return $a['preferred_name'] ? -1 : 1;
        }
        if ($a['has_begindoc'] !== $b['has_begindoc']) {
            return $a['has_begindoc'] ? -1 : 1;
        }

        return $b['len'] <=> $a['len'];
    });

    return (string) array_key_first($candidates);
}

/**
 * Resolve a \input{rel/path} or \include{rel/path} target against the
 * directory of $fromFile, allowing the user to omit the .tex extension
 * (TeX's own behaviour). Returns the matching key from $texFiles, or ''
 * if nothing matched (caller will leave the original macro alone).
 *
 * @param array<string,string> $texFiles
 */
function tex_resolve_include(string $fromFile, string $target, array $texFiles): string
{
    $target = trim($target);
    if ($target === '') {
        return '';
    }
    $dir = trim(str_replace('\\', '/', dirname($fromFile)), '/');
    $candidates = [];
    $clean = ltrim(str_replace('\\', '/', $target), './');
    $candidates[] = $clean;
    if ($dir !== '' && $dir !== '.') {
        $candidates[] = $dir . '/' . $clean;
    }
    $withExt = [];
    foreach ($candidates as $candidate) {
        $withExt[] = $candidate;
        if (!preg_match('/\.(tex|ltx)$/i', $candidate)) {
            $withExt[] = $candidate . '.tex';
            $withExt[] = $candidate . '.ltx';
        }
    }
    foreach ($withExt as $candidate) {
        if (isset($texFiles[$candidate])) {
            return $candidate;
        }
    }
    $needleLower = strtolower(end($withExt));
    foreach (array_keys($texFiles) as $real) {
        if (strtolower(basename($real)) === $needleLower) {
            return $real;
        }
    }

    return '';
}

/**
 * Recursively inline \input / \include / \subfile references so the
 * downstream plain-text converter sees a single document. Visited files
 * are tracked to break input cycles, which are surprisingly common when
 * authors reuse boilerplate.
 *
 * @param array<string,string> $texFiles
 * @param array<string,bool> $visited
 */
function tex_inline_inputs(string $text, string $fromFile, array $texFiles, array $visited = []): string
{
    $visited[$fromFile] = true;

    return (string) preg_replace_callback(
        '/\\\\(?:input|include|subfile)\s*\{([^}]+)\}/',
        static function (array $m) use ($fromFile, $texFiles, $visited): string {
            $resolved = tex_resolve_include($fromFile, (string) $m[1], $texFiles);
            if ($resolved === '' || isset($visited[$resolved])) {
                return '';
            }

            return "\n" . tex_inline_inputs($texFiles[$resolved], $resolved, $texFiles, $visited) . "\n";
        },
        $text
    );
}

/**
 * Drop common preamble-only blocks (between \documentclass and
 * \begin{document}, plus everything after \end{document}). When neither
 * delimiter is present, returns the input unchanged.
 */
function tex_strip_preamble(string $text): string
{
    if (preg_match('/\\\\begin\{document\}/', $text, $m, PREG_OFFSET_CAPTURE)) {
        $start = (int) $m[0][1] + strlen((string) $m[0][0]);
        $text = (string) substr($text, $start);
    }
    if (preg_match('/\\\\end\{document\}/', $text, $m, PREG_OFFSET_CAPTURE)) {
        $text = (string) substr($text, 0, (int) $m[0][1]);
    }

    return $text;
}

/**
 * Strip LaTeX comments. `%` introduces a comment to end of line *unless*
 * preceded by an odd number of backslashes (`\%` is a literal percent).
 * Whole-line comments swallow the trailing newline so paragraphs join,
 * matching TeX's own behaviour.
 */
function tex_strip_comments(string $text): string
{
    $text = (string) preg_replace('/(?<!\\\\)((?:\\\\\\\\)*)%[^\n]*\n[ \t]*/', '$1', $text);

    return (string) preg_replace('/(?<!\\\\)((?:\\\\\\\\)*)%[^\n]*/', '$1', $text);
}

/**
 * Remove environments wholesale (figures, tables, math display blocks,
 * tikz, bibliographies inside .tex, …). We keep environments whose body
 * is prose (`abstract`, `quote`, `quotation`) by handling them separately.
 *
 * @param list<string> $names
 */
function tex_strip_environments(string $text, array $names): string
{
    foreach ($names as $name) {
        $escaped = preg_quote($name, '/');
        $pattern = '/\\\\begin\{' . $escaped . '\*?\}.*?\\\\end\{' . $escaped . '\*?\}/s';
        // Loop because nested same-name environments can stack inside the
        // first match's span; one pass would leave the outer wrapper open.
        $prev = null;
        while ($prev !== $text) {
            $prev = $text;
            $text = (string) preg_replace($pattern, '', $text);
        }
    }

    return $text;
}

/**
 * Convert `itemize`/`enumerate`/`description` blocks into markdown lists
 * so the viewer renders them with markers and our offset math stays sane.
 */
function tex_convert_lists(string $text): string
{
    $text = (string) preg_replace_callback(
        '/\\\\begin\{itemize\*?\}(.*?)\\\\end\{itemize\*?\}/s',
        static fn (array $m): string => tex_render_list((string) $m[1], false),
        $text
    );
    $text = (string) preg_replace_callback(
        '/\\\\begin\{enumerate\*?\}(.*?)\\\\end\{enumerate\*?\}/s',
        static fn (array $m): string => tex_render_list((string) $m[1], true),
        $text
    );
    $text = (string) preg_replace_callback(
        '/\\\\begin\{description\*?\}(.*?)\\\\end\{description\*?\}/s',
        static fn (array $m): string => tex_render_list((string) $m[1], false),
        $text
    );

    return $text;
}

function tex_render_list(string $body, bool $numbered): string
{
    $body = trim($body);
    if ($body === '') {
        return '';
    }
    $parts = preg_split('/\\\\item(?:\[[^\]]*\])?\s*/', $body);
    if (!is_array($parts)) {
        return $body;
    }
    array_shift($parts);
    $out = [];
    $i = 1;
    foreach ($parts as $part) {
        $part = trim($part);
        if ($part === '') {
            continue;
        }
        $out[] = ($numbered ? ($i . '. ') : '- ') . $part;
        $i++;
    }

    return "\n\n" . implode("\n", $out) . "\n\n";
}

/**
 * Resolve a `\includegraphics{path}` target against the archive's image
 * files. TeX searches a graphicspath and tries each known extension when
 * the user wrote a bare stem — we emulate the common cases (current file's
 * dir, archive root, prefix-stripped variants) plus extension probing.
 *
 * Returns the matching key from $mediaBlobs, or '' if nothing matched.
 *
 * @param array<string,string> $mediaBlobs
 */
function tex_resolve_image(string $fromFile, string $target, array $mediaBlobs): string
{
    $target = trim($target);
    if ($target === '') {
        return '';
    }
    $clean = ltrim(str_replace('\\', '/', $target), './');
    $dir = trim(str_replace('\\', '/', dirname($fromFile)), '/');

    $bases = [$clean];
    if ($dir !== '' && $dir !== '.') {
        $bases[] = $dir . '/' . $clean;
    }

    $candidates = [];
    foreach ($bases as $base) {
        $candidates[] = $base;
        if (pathinfo($base, PATHINFO_EXTENSION) === '') {
            foreach (array_merge(TEX_IMAGE_INLINE_EXTS, TEX_IMAGE_LINK_EXTS) as $ext) {
                $candidates[] = $base . '.' . $ext;
            }
        }
    }

    foreach ($candidates as $candidate) {
        if (isset($mediaBlobs[$candidate])) {
            return $candidate;
        }
    }

    // Case-insensitive fallback by basename — arXiv submitters frequently
    // capitalise differently in source than they typed in \includegraphics.
    $needleLower = strtolower(basename($clean));
    $needleLowerWithExt = pathinfo($needleLower, PATHINFO_EXTENSION) === '' ? null : $needleLower;
    foreach (array_keys($mediaBlobs) as $real) {
        $realBase = strtolower(basename($real));
        if ($needleLowerWithExt !== null && $realBase === $needleLowerWithExt) {
            return $real;
        }
        if ($needleLowerWithExt === null && pathinfo($realBase, PATHINFO_FILENAME) === $needleLower) {
            return $real;
        }
    }

    return '';
}

/**
 * Stage a referenced image file from the in-memory map onto disk under the
 * caller-supplied media directory, deduplicating by destination basename.
 * Returns null when the source data isn't in the map (caller will drop the
 * reference) or when the file can't be written. The returned record
 * carries everything the caller needs to compose a markdown image token.
 *
 * @param array<string,string> $mediaBlobs   relPath => raw bytes
 * @param array<string,array> $staged        accumulator, keyed by relPath
 * @return array{name:string,url:string,inline:bool,archive_path:string,bytes:int}|null
 */
function tex_stage_image_to_disk(string $relPath, array $mediaBlobs, string $mediaDir, string $mediaUrlBase, array &$staged): ?array
{
    if (isset($staged[$relPath])) {
        return $staged[$relPath];
    }
    if (!isset($mediaBlobs[$relPath])) {
        return null;
    }
    if (!is_dir($mediaDir) && !@mkdir($mediaDir, 0775, true) && !is_dir($mediaDir)) {
        return null;
    }

    $ext = strtolower(pathinfo($relPath, PATHINFO_EXTENSION));
    $rawBase = (string) pathinfo($relPath, PATHINFO_FILENAME);
    // Squash anything that isn't safe for a filename on common filesystems
    // *and* in a URL path; underscores collapse to single underscores so
    // the result stays readable.
    $safeBase = preg_replace('/[^A-Za-z0-9._-]+/', '_', $rawBase) ?? '';
    $safeBase = trim((string) $safeBase, '._-');
    if ($safeBase === '') {
        $safeBase = 'image';
    }
    $candidate = $safeBase . '.' . $ext;
    $destPath = $mediaDir . '/' . $candidate;
    $suffix = 0;
    while (is_file($destPath)) {
        $existing = @file_get_contents($destPath);
        if (is_string($existing) && $existing === $mediaBlobs[$relPath]) {
            // Identical file already staged under this name — reuse it.
            break;
        }
        $suffix += 1;
        $candidate = $safeBase . '-' . $suffix . '.' . $ext;
        $destPath = $mediaDir . '/' . $candidate;
    }
    if (!is_file($destPath)) {
        if (@file_put_contents($destPath, $mediaBlobs[$relPath]) === false) {
            return null;
        }
        @chmod($destPath, 0664);
    }

    $url = $mediaUrlBase . rawurlencode($candidate);
    $record = [
        'name' => $candidate,
        'url' => $url,
        'inline' => in_array($ext, TEX_IMAGE_INLINE_EXTS, true),
        'archive_path' => $relPath,
        'bytes' => strlen($mediaBlobs[$relPath]),
    ];
    $staged[$relPath] = $record;

    return $record;
}

/**
 * Pre-pass over the inlined TeX: substitute every figure-like environment
 * and every bare \includegraphics with markdown image / link syntax that
 * survives `tex_to_plain_text`. The accumulator $staged is filled with one
 * record per unique staged image. We *also* strip captions, refs, labels,
 * and subfigure scaffolding inside the figure so the visible body is just
 * "[image]\n\nCaption: …".
 *
 * @param array<string,string> $mediaBlobs
 * @param array<string,array>  $staged
 */
function tex_substitute_figures(string $tex, string $mainFile, array $mediaBlobs, string $mediaDir, string $mediaUrlBase, array &$staged): string
{
    if ($mediaBlobs === []) {
        return $tex;
    }

    $emitImageMarkdown = function (string $imagePath, string $alt) use ($mainFile, $mediaBlobs, $mediaDir, $mediaUrlBase, &$staged): string {
        $resolved = tex_resolve_image($mainFile, $imagePath, $mediaBlobs);
        if ($resolved === '') {
            return '';
        }
        $record = tex_stage_image_to_disk($resolved, $mediaBlobs, $mediaDir, $mediaUrlBase, $staged);
        if ($record === null) {
            return '';
        }
        $altSafe = preg_replace('/[\r\n\[\]]+/', ' ', $alt) ?? '';
        $altSafe = trim((string) $altSafe);
        if ($altSafe === '') {
            $altSafe = $record['name'];
        }
        return $record['inline']
            ? '![' . $altSafe . '](' . $record['url'] . ')'
            : '[Figure: ' . $altSafe . '](' . $record['url'] . ')';
    };

    // Pass 1: figure-like environments.
    $envNames = ['figure', 'figure*', 'wrapfigure', 'sidewaysfigure', 'SCfigure', 'subfigure', 'minipage'];
    foreach ($envNames as $envName) {
        $envEscaped = preg_quote($envName, '/');
        $tex = (string) preg_replace_callback(
            '/\\\\begin\{' . $envEscaped . '\}(?:\[[^\]]*\])?(?:\{[^}]*\})?(.*?)\\\\end\{' . $envEscaped . '\}/s',
            function (array $m) use ($emitImageMarkdown): string {
                $body = (string) $m[1];

                // Captions first — there may be more than one (subfigures).
                $captions = [];
                if (preg_match_all('/\\\\caption(?:\[[^\]]*\])?\{((?:[^{}]|\{[^{}]*\})*)\}/', $body, $cm)) {
                    foreach ($cm[1] as $c) {
                        $clean = trim(tex_to_plain_text((string) $c));
                        if ($clean !== '') {
                            $captions[] = $clean;
                        }
                    }
                }
                $caption = implode(' / ', $captions);

                $lines = [];
                if (preg_match_all('/\\\\includegraphics(?:\s*\[[^\]]*\])?\s*\{([^}]+)\}/', $body, $im)) {
                    foreach ($im[1] as $imgPath) {
                        $md = $emitImageMarkdown((string) $imgPath, $caption);
                        if ($md !== '') {
                            $lines[] = $md;
                        }
                    }
                }

                if ($lines === []) {
                    // No images we could resolve — keep just the caption so
                    // the user still sees a hint that a figure was here.
                    return $caption !== '' ? "\n\nFigure: " . $caption . "\n\n" : "\n\n";
                }
                $block = "\n\n" . implode("\n\n", $lines) . "\n\n";
                if ($caption !== '') {
                    $block .= 'Caption: ' . $caption . "\n\n";
                }
                return $block;
            },
            $tex
        );
    }

    // Pass 2: any \includegraphics left outside figures.
    $tex = (string) preg_replace_callback(
        '/\\\\includegraphics(?:\s*\[[^\]]*\])?\s*\{([^}]+)\}/',
        function (array $m) use ($emitImageMarkdown): string {
            $md = $emitImageMarkdown((string) $m[1], '');
            return $md === '' ? '' : "\n\n" . $md . "\n\n";
        },
        $tex
    );

    return $tex;
}

/**
 * Top-level LaTeX → plain text. Order matters: preamble stripped first
 * (avoids parsing `\documentclass[…]{…}` like prose), then environments,
 * then inline macros, then escape sequences, then whitespace tidy-up.
 */
function tex_to_plain_text(string $tex): string
{
    $tex = tex_strip_comments($tex);

    // Most papers put \title{…} / \author{…} in the preamble and emit the
    // visible title with \maketitle. Capture those before stripping the
    // preamble so a reader still sees the head matter.
    $title = '';
    $author = '';
    if (preg_match('/\\\\title\s*\{((?:[^{}]|\{[^{}]*\})*)\}/', $tex, $m)) {
        $title = trim((string) $m[1]);
    }
    if (preg_match('/\\\\author\s*\{((?:[^{}]|\{[^{}]*\})*)\}/', $tex, $m)) {
        $author = trim((string) $m[1]);
    }

    $tex = tex_strip_preamble($tex);

    $headMatter = '';
    if ($title !== '') {
        $headMatter .= "\n# " . $title . "\n";
    }
    if ($author !== '') {
        $headMatter .= "\n*" . $author . "*\n";
    }
    if ($headMatter !== '') {
        $tex = preg_match('/\\\\maketitle\b/', $tex) === 1
            ? (string) preg_replace('/\\\\maketitle\b/', $headMatter, $tex, 1)
            : $headMatter . "\n" . $tex;
    }

    $tex = tex_strip_environments($tex, [
        'figure', 'figure*', 'table', 'table*', 'tabular', 'tabularx', 'tabbing',
        'tikzpicture', 'pgfpicture', 'minipage', 'wrapfigure', 'sidewaysfigure',
        'sidewaystable', 'eqnarray', 'eqnarray*', 'align', 'align*', 'gather',
        'gather*', 'multline', 'multline*', 'equation', 'equation*', 'displaymath',
        'array', 'matrix', 'pmatrix', 'bmatrix', 'vmatrix', 'thebibliography',
        'tcolorbox', 'algorithm', 'algorithm*', 'algorithmic', 'verbatim',
        'lstlisting', 'minted', 'comment', 'CCSXML',
    ]);

    foreach (['abstract', 'quote', 'quotation', 'sloppypar', 'sloppy', 'small', 'footnotesize'] as $passthrough) {
        $escaped = preg_quote($passthrough, '/');
        $tex = (string) preg_replace('/\\\\begin\{' . $escaped . '\*?\}/', "\n\n", $tex);
        $tex = (string) preg_replace('/\\\\end\{' . $escaped . '\*?\}/', "\n\n", $tex);
    }

    $tex = tex_convert_lists($tex);

    $tex = (string) preg_replace_callback(
        '/\\\\(chapter|section|subsection|subsubsection|paragraph|subparagraph)\*?\s*(?:\[[^\]]*\])?\s*\{((?:[^{}]|\{[^{}]*\})*)\}/',
        static function (array $m): string {
            $hashes = match ((string) $m[1]) {
                'chapter' => '#',
                'section' => '#',
                'subsection' => '##',
                'subsubsection' => '###',
                'paragraph' => '####',
                'subparagraph' => '#####',
                default => '##',
            };
            $title = tex_to_plain_text((string) $m[2]);

            return "\n\n" . $hashes . ' ' . trim($title) . "\n\n";
        },
        $tex
    );

    $tex = (string) preg_replace('/\\\\title\s*\{((?:[^{}]|\{[^{}]*\})*)\}/', "\n# $1\n", $tex);
    $tex = (string) preg_replace('/\\\\author\s*\{((?:[^{}]|\{[^{}]*\})*)\}/', "\n*$1*\n", $tex);
    $tex = (string) preg_replace('/\\\\date\s*\{((?:[^{}]|\{[^{}]*\})*)\}/', '', $tex);
    $tex = (string) preg_replace('/\\\\maketitle\b/', '', $tex);
    $tex = (string) preg_replace('/\\\\thanks\s*\{((?:[^{}]|\{[^{}]*\})*)\}/', '', $tex);

    $tex = (string) preg_replace('/\\\\href\s*\{([^}]*)\}\s*\{((?:[^{}]|\{[^{}]*\})*)\}/', '[$2]($1)', $tex);
    $tex = (string) preg_replace('/\\\\url\s*\{([^}]*)\}/', '$1', $tex);
    $tex = (string) preg_replace('/\\\\nolinkurl\s*\{([^}]*)\}/', '$1', $tex);

    $tex = (string) preg_replace('/\\\\footnotemark(?:\[[^\]]*\])?/', '', $tex);
    $tex = (string) preg_replace('/\\\\footnote\s*\{((?:[^{}]|\{[^{}]*\})*)\}/', ' ($1)', $tex);
    $tex = (string) preg_replace('/\\\\(?:cite|citep|citet|citeauthor|citeyear|citealp|citealt|parencite|textcite|autocite|fullcite|nocite)\s*(?:\[[^\]]*\])?\s*(?:\[[^\]]*\])?\s*\{([^}]*)\}/', '($1)', $tex);

    $tex = (string) preg_replace('/\\\\(?:label|index|nocite|hypertarget|hyperlink|bibliography|bibliographystyle|input|include|subfile|usepackage|RequirePackage|DeclareMathOperator|newcommand|renewcommand|providecommand|newenvironment|setlength|setcounter|addtolength|definecolor|color|textcolor|colorbox|fcolorbox|graphicspath|includegraphics|caption|captionof|captionsetup|listoffigures|listoftables|tableofcontents|appendix|markboth|markright|pagestyle|thispagestyle|fancyhead|fancyfoot|titlespacing|geometry|setmainfont|setsansfont|setmonofont|titleformat|titlespacing)\s*(?:\[[^\]]*\])?\s*\{((?:[^{}]|\{[^{}]*\})*)\}/', '', $tex);
    $tex = (string) preg_replace('/\\\\(?:noindent|newpage|clearpage|cleardoublepage|linebreak|pagebreak|smallskip|medskip|bigskip|vfill|hfill|hrule|hline|cline|midrule|toprule|bottomrule|centering|raggedright|raggedleft|justify|sloppy|fussy|protect|relax|ignorespaces|allowbreak|leavevmode|par|setlength|\,|\;|\:|\!|\/)\b/', '', $tex);

    $tex = (string) preg_replace('/\\\\(?:emph|textit|textsl)\s*\{((?:[^{}]|\{[^{}]*\})*)\}/', '*$1*', $tex);
    $tex = (string) preg_replace('/\\\\(?:textbf|bfseries|strong)\s*\{((?:[^{}]|\{[^{}]*\})*)\}/', '**$1**', $tex);
    $tex = (string) preg_replace('/\\\\(?:texttt|textsf|textsc|textrm|textnormal|textmd|underline|uline|sout|st|textsuperscript|textsubscript)\s*\{((?:[^{}]|\{[^{}]*\})*)\}/', '$1', $tex);
    $tex = (string) preg_replace('/\\\\(?:ref|eqref|pageref|autoref|cref|Cref|nameref|vref)\s*\{([^}]*)\}/', '[$1]', $tex);

    $tex = (string) preg_replace_callback('/\\\\(LaTeX|TeX|XeLaTeX|LuaLaTeX|BibTeX)\b/', static fn (array $m): string => (string) $m[1], $tex);

    $tex = (string) preg_replace('/\\\\\\\\(?:\s*\[[^\]]*\])?/', "\n", $tex);

    $tex = strtr($tex, [
        '\\&' => '&',
        '\\$' => '$',
        '\\_' => '_',
        '\\%' => '%',
        '\\#' => '#',
        '\\{' => '{',
        '\\}' => '}',
        '\\~' => '~',
        '\\^' => '^',
        '\\textbackslash' => '\\',
        '\\ldots' => '…',
        '\\dots' => '…',
        '\\cdots' => '⋯',
        '\\vdots' => '⋮',
        '\\ddots' => '⋱',
        '\\textellipsis' => '…',
        '\\textendash' => '–',
        '\\textemdash' => '—',
        '\\textquoteleft' => '‘',
        '\\textquoteright' => '’',
        '\\textquotedblleft' => '“',
        '\\textquotedblright' => '”',
        '\\textbar' => '|',
        '\\textless' => '<',
        '\\textgreater' => '>',
        "\\\n" => "\n",
        '~' => ' ',
    ]);

    $tex = strtr($tex, [
        '---' => '—',
        '--' => '–',
        "``" => '“',
        "''" => '”',
    ]);

    $tex = (string) preg_replace('/\\\\[A-Za-z]+\*?\s*(?:\[[^\]]*\])*\s*\{((?:[^{}]|\{[^{}]*\})*)\}/', '$1', $tex);
    $tex = (string) preg_replace('/\\\\[A-Za-z]+\*?\b/', '', $tex);

    $tex = strtr($tex, ['{' => '', '}' => '']);

    $tex = (string) preg_replace('/[ \t]+\n/', "\n", $tex);
    $tex = (string) preg_replace('/\n{3,}/', "\n\n", $tex);
    $tex = (string) preg_replace('/[ \t]{2,}/', ' ', $tex);

    return trim($tex);
}
