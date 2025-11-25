<?php

declare(strict_types=1);

namespace App\Service;

use RuntimeException;

class FileRewriteService
{
    public function __construct(
        private FileWriteService $fileWriteService,
    ) {}

    /**
     * Rewrite entire file contents atomically with hash-based verification
     *
     * @param string $pathAndFilename
     * @param string $fileQuickHash Previous quick_hash to verify file hasn't changed
     * @param string $content Complete new file contents
     * @return array{
     *     success: bool,
     *     file: string,
     *     new_file_quick_hash: string,
     *     checksum: string,
     *     metadata: array{
     *         original_line_count: int,
     *         new_line_count: int,
     *         original_size: int,
     *         new_size: int
     *     }
     * }
     * @throws RuntimeException
     */
    public function rewriteFile(
        string $pathAndFilename,
        string $fileQuickHash,
        string $content
    ): array {
        // Verify file exists
        if (!file_exists($pathAndFilename)) {
            throw new RuntimeException("File not found: {$pathAndFilename}");
        }

        // Verify file is readable
        if (!is_readable($pathAndFilename)) {
            throw new RuntimeException("File is not readable: {$pathAndFilename}");
        }

        // Get current file hash
        $currentQuickHash = $this->fileWriteService->calculateFileQuickHash($pathAndFilename);
        
        // Verify hash matches (optimistic locking)
        if ($currentQuickHash !== $fileQuickHash) {
            throw new RuntimeException(
                "File has changed since last read. Expected quick_hash: {$fileQuickHash}, got: {$currentQuickHash}"
            );
        }

        // Read original content for metadata
        $originalContent = file_get_contents($pathAndFilename);
        if ($originalContent === false) {
            throw new RuntimeException("Failed to read file: {$pathAndFilename}");
        }

        // Count lines for metadata
        $originalLines = explode("\n", $originalContent);
        $newLines = explode("\n", $content);

        try {
            // Write new content atomically using shared method
            $this->fileWriteService->writeFileAtomically($pathAndFilename, $content);

            // Calculate new checksums
            $newChecksum = hash('sha256', $content);
            $newQuickHash = $this->fileWriteService->calculateFileQuickHash($pathAndFilename);

            return [
                'success' => true,
                'file' => $pathAndFilename,
                'new_file_quick_hash' => $newQuickHash,
                'checksum' => $newChecksum,
                'metadata' => [
                    'original_line_count' => count($originalLines),
                    'new_line_count' => count($newLines),
                    'original_size' => strlen($originalContent),
                    'new_size' => strlen($content),
                ],
            ];
        } catch (RuntimeException $e) {
            throw new RuntimeException(
                "Failed to rewrite file {$pathAndFilename}: " . $e->getMessage(),
                0,
                $e
            );
        }
    }
}
