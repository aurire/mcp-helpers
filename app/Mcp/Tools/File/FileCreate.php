<?php

declare(strict_types=1);

namespace App\Mcp\Tools\File;

use App\Service\FileToolService;
use PhpMcp\Server\Attributes\McpTool;
use RuntimeException;

class FileCreate
{
    public function __construct(
        private FileToolService $fileToolService
    ) {}

    /**
     * Create a new file with initial content
     *
     * Usage:
     * - Specify path where file should be created
     * - Provide initial content (can be empty string)
     * - File must not already exist
     *
     * Example:
     * - path: "/path/to/newfile.php"
     * - content: "<?php\n\necho 'hello';"
     */
    #[McpTool(name: 'file_create')]
    public function fileCreate(
        string $pathAndFilename,
        string $content = ''
    ): array {
        // Validate path is allowed
        $allowedPaths = $this->fileToolService->getAllowedPaths();
        if (!$this->fileToolService->isPathAllowed($allowedPaths, $pathAndFilename)) {
            throw new RuntimeException("Access denied: Path is not within allowed directories");
        }

        // Check if file already exists
        if (file_exists($pathAndFilename)) {
            throw new RuntimeException("File already exists: {$pathAndFilename}");
        }

        // Ensure directory exists
        $directory = dirname($pathAndFilename);
        if (!is_dir($directory)) {
            throw new RuntimeException("Directory does not exist: {$directory}");
        }

        // Check if directory is writable
        if (!is_writable($directory)) {
            throw new RuntimeException("Directory is not writable: {$directory}");
        }

        // Write file atomically using temp file + rename
        $basename = basename($pathAndFilename);
        $tempFile = $directory . DIRECTORY_SEPARATOR . '.tmp_' . $basename . '.' . uniqid();

        if (file_put_contents($tempFile, $content, LOCK_EX) === false) {
            throw new RuntimeException("Failed to write temporary file: {$tempFile}");
        }

        // Atomic rename
        if (!rename($tempFile, $pathAndFilename)) {
            @unlink($tempFile);
            throw new RuntimeException("Failed to create file atomically: {$pathAndFilename}");
        }

        // Read back the created file with full metadata
        $newFile = $this->fileToolService->readFileAndPrepareResults($pathAndFilename);

        return [
            'success' => true,
            'file' => $pathAndFilename,
            'created' => true,
            'size' => strlen($content),
            'checksum' => $newFile['checksum'],
            'file_quick_hash' => $newFile['file_quick_hash'],
            'new_file' => $newFile,
        ];
    }
}
