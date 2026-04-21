<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

function pdf_storage_path(): string
{
    $configured = trim((string) setting('zotero_local_storage_path', ''));
    $base = $configured !== '' ? $configured : (getenv('HOME') ?: '~');
    if (str_starts_with($base, '~/')) {
        $home = getenv('HOME') ?: '';
        if ($home !== '') {
            $base = $home . substr($base, 1);
        }
    }
    $base = rtrim($base, '/');

    if (str_ends_with($base, '/storage')) {
        return $base;
    }
    if (str_ends_with($base, '/Zotero')) {
        return $base . '/storage';
    }

    return $base . '/Zotero/storage';
}

function pdf_resolve_path(string $itemKey): string
{
    $itemKey = trim($itemKey);
    if ($itemKey === '') {
        return '';
    }

    $dir = pdf_storage_path() . '/' . $itemKey;
    if (!is_dir($dir)) {
        return '';
    }

    $matches = glob($dir . '/*.pdf') ?: [];
    if ($matches === []) {
        $matches = glob($dir . '/*.PDF') ?: [];
    }

    return isset($matches[0]) ? (string) $matches[0] : '';
}

function pdf_extract_text(string $pdfPath): string
{
    $pdfPath = trim($pdfPath);
    if ($pdfPath === '' || !is_file($pdfPath)) {
        throw new RuntimeException('PDF file does not exist.');
    }

    $swift = '/usr/bin/swift';
    if (!is_file($swift)) {
        throw new RuntimeException('Swift runtime is not available on this machine.');
    }

    $scriptPath = dirname(__DIR__) . '/scripts/ocr.swift';
    if (!is_file($scriptPath)) {
        throw new RuntimeException('OCR script is missing.');
    }

    $command = escapeshellarg($swift) . ' ' . escapeshellarg($scriptPath) . ' ' . escapeshellarg($pdfPath) . ' 2>&1';
    $output = shell_exec($command);
    if (!is_string($output)) {
        throw new RuntimeException('PDF OCR command failed.');
    }

    return trim($output);
}

function pdf_open_in_finder(string $pdfPath): void
{
    $pdfPath = trim($pdfPath);
    if ($pdfPath === '' || !is_file($pdfPath)) {
        throw new RuntimeException('PDF file does not exist.');
    }

    $output = [];
    $exitCode = 0;
    exec('/usr/bin/open -R ' . escapeshellarg($pdfPath) . ' 2>&1', $output, $exitCode);
    if ($exitCode !== 0) {
        $message = trim(implode("\n", $output));
        throw new RuntimeException($message !== '' ? $message : 'Could not open Finder for PDF.');
    }
}
