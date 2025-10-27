<?php

declare(strict_types=1);

namespace App\Service;

use RuntimeException;

class FileWriteService
{
    public function __construct(
        private FileToolService $fileToolService,
    ) {}

    /**
     * Insert lines at a specific position with verification and locking
     *
     * @param string $pathAndFilename
     * @param int $lineNumber 1-based line number to insert at
     * @param string $referenceLineContent The exact content of the reference line (for verification)
     * @param array<string> $linesToInsert Lines to insert
     * @param string $fileQuickHash Previous quick_hash to verify file hasn't changed
     * @param bool $insertAfter Whether to insert after the reference line (true) or before (false)
     * @return array{
     *     success: bool,
     *     checksum: string,
     *     file_quick_hash: string,
     *     line_count: int,
     *     inserted_line_count: int,
     *     message?: string
     * }
     */
    public function insertLines(
        string $pathAndFilename,
        int $lineNumber,
        string $referenceLineContent,
        array $linesToInsert,
        string $fileQuickHash,
        bool $insertAfter = false
    ): array {
        // Verify file exists and is readable
        if (!file_exists($pathAndFilename)) {
            throw new RuntimeException("File not found: {$pathAndFilename}");
        }

        if (!is_readable($pathAndFilename)) {
            throw new RuntimeException("File is not readable: {$pathAndFilename}");
        }

        // Verify quick hash matches (optimistic locking)
        $currentQuickHash = $this->calculateFileQuickHash($pathAndFilename);
        if ($currentQuickHash !== $fileQuickHash) {
            throw new RuntimeException(
                "File has changed since last read. Expected quick_hash: {$fileQuickHash}, got: {$currentQuickHash}"
            );
        }

        // Read file into lines
        $contents = file_get_contents($pathAndFilename);
        if ($contents === false) {
            throw new RuntimeException("Failed to read file: {$pathAndFilename}");
        }

        $lines = explode("\n", $contents);

        // Verify reference line content
        $index = $lineNumber - 1; // Convert to 0-indexed
        if (!isset($lines[$index])) {
            throw new RuntimeException(
                "Line {$lineNumber} not found in file. File has " . count($lines) . " lines."
            );
        }

        $actualLineContent = $lines[$index];
        if ($actualLineContent !== $referenceLineContent) {
            throw new RuntimeException(
                "Reference line content mismatch at line {$lineNumber}. " .
                "Expected: " . substr($referenceLineContent, 0, 50) . "... " .
                "Got: " . substr($actualLineContent, 0, 50) . "..."
            );
        }

        // Insert lines at the specified position
        $insertIndex = $insertAfter ? $index + 1 : $index;
        array_splice($lines, $insertIndex, 0, $linesToInsert);

        // Reconstruct content
        $newContents = implode("\n", $lines);

        // Write to file atomically
        $this->writeFileAtomically($pathAndFilename, $newContents);

        // Calculate new checksums
        $newChecksum = hash('sha256', $newContents);
        $newQuickHash = $this->calculateQuickHashFromContent($pathAndFilename, $newContents);

        return [
            'success' => true,
            'checksum' => $newChecksum,
            'file_quick_hash' => $newQuickHash,
            'line_count' => count($lines),
            'inserted_line_count' => count($linesToInsert),
        ];
    }

    /**
     * Delete lines in a range with verification
     *
     * @param string $pathAndFilename
     * @param int $startLine 1-based
     * @param int $endLine 1-based
     * @param string $fileQuickHash Previous quick_hash for verification
     * @return array{
     *     success: bool,
     *     checksum: string,
     *     file_quick_hash: string,
     *     line_count: int,
     *     deleted_line_count: int
     * }
     */
    public function deleteLines(
        string $pathAndFilename,
        int $startLine,
        int $endLine,
        string $fileQuickHash
    ): array {
        if (!file_exists($pathAndFilename)) {
            throw new RuntimeException("File not found: {$pathAndFilename}");
        }

        // Verify quick hash
        $currentQuickHash = $this->calculateFileQuickHash($pathAndFilename);
        if ($currentQuickHash !== $fileQuickHash) {
            throw new RuntimeException(
                "File has changed since last read. Expected quick_hash: {$fileQuickHash}, got: {$currentQuickHash}"
            );
        }

        $contents = file_get_contents($pathAndFilename);
        if ($contents === false) {
            throw new RuntimeException("Failed to read file: {$pathAndFilename}");
        }

        $lines = explode("\n", $contents);
        $deletedCount = $this->fileToolService->deleteLines($lines, $startLine, $endLine);

        $newContents = implode("\n", $deletedCount);

        $this->writeFileAtomically($pathAndFilename, $newContents);

        $newChecksum = hash('sha256', $newContents);
        $newQuickHash = $this->calculateQuickHashFromContent($pathAndFilename, $newContents);

        return [
            'success' => true,
            'checksum' => $newChecksum,
            'file_quick_hash' => $newQuickHash,
            'line_count' => count($deletedCount),
            'deleted_line_count' => $endLine - $startLine + 1,
        ];
    }

    /**
     * Replace lines in a range
     *
     * @param string $pathAndFilename
     * @param int $startLine 1-based
     * @param int $endLine 1-based
     * @param array<string> $replacementLines
     * @param string $fileQuickHash
     * @return array{
     *     success: bool,
     *     checksum: string,
     *     file_quick_hash: string,
     *     line_count: int,
     *     replaced_line_count: int
     * }
     */
    public function replaceLines(
        string $pathAndFilename,
        int $startLine,
        int $endLine,
        array $replacementLines,
        string $fileQuickHash
    ): array {
        if (!file_exists($pathAndFilename)) {
            throw new RuntimeException("File not found: {$pathAndFilename}");
        }

        $currentQuickHash = $this->calculateFileQuickHash($pathAndFilename);
        if ($currentQuickHash !== $fileQuickHash) {
            throw new RuntimeException(
                "File has changed since last read. Expected quick_hash: {$fileQuickHash}, got: {$currentQuickHash}"
            );
        }

        $contents = file_get_contents($pathAndFilename);
        if ($contents === false) {
            throw new RuntimeException("Failed to read file: {$pathAndFilename}");
        }

        $lines = explode("\n", $contents);

        // Verify line range
        $start = $startLine - 1;
        $end = $endLine - 1;
        if ($start < 0 || $end >= count($lines) || $start > $end) {
            throw new RuntimeException("Invalid line range: {$startLine}-{$endLine}");
        }

        // Replace lines
        $removeCount = $end - $start + 1;
        array_splice($lines, $start, $removeCount, $replacementLines);

        $newContents = implode("\n", $lines);

        $this->writeFileAtomically($pathAndFilename, $newContents);

        $newChecksum = hash('sha256', $newContents);
        $newQuickHash = $this->calculateQuickHashFromContent($pathAndFilename, $newContents);

        return [
            'success' => true,
            'checksum' => $newChecksum,
            'file_quick_hash' => $newQuickHash,
            'line_count' => count($lines),
            'replaced_line_count' => $removeCount,
        ];
    }

    /**
     * Calculate quick hash for a file (xxh3 based on path, size, mtime)
     */
    public function calculateFileQuickHash(string $pathAndFilename): string
    {
        if (!file_exists($pathAndFilename)) {
            throw new RuntimeException("File not found: {$pathAndFilename}");
        }

        $size = filesize($pathAndFilename);
        $mtime = filemtime($pathAndFilename);

        if ($size === false || $mtime === false) {
            throw new RuntimeException("Failed to get file metadata: {$pathAndFilename}");
        }

        return hash('xxh3', $pathAndFilename . ':' . $size . ':' . $mtime);
    }

    /**
     * Calculate quick hash from content (for post-write verification)
     */
    private function calculateQuickHashFromContent(string $pathAndFilename, string $content): string
    {
        // After writing, the file metadata has changed, so we generate based on current state
        // This will be different from before, which is expected
        $size = strlen($content);
        $mtime = time(); // Use current time as approximation

        return hash('xxh3', $pathAndFilename . ':' . $size . ':' . $mtime);
    }

    /**
     * Write file atomically using temp file + rename
     */
    private function writeFileAtomically(string $pathAndFilename, string $content): void
    {
        $directory = dirname($pathAndFilename);
        $basename = basename($pathAndFilename);

        // Create temp file in same directory for atomic rename
        $tempFile = $directory . DIRECTORY_SEPARATOR . '.tmp_' . $basename . '.' . uniqid();

        if (file_put_contents($tempFile, $content, LOCK_EX) === false) {
            throw new RuntimeException("Failed to write temporary file: {$tempFile}");
        }

        // Atomic rename
        if (!rename($tempFile, $pathAndFilename)) {
            @unlink($tempFile);
            throw new RuntimeException("Failed to write file atomically: {$pathAndFilename}");
        }
    }
}
