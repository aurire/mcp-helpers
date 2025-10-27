<?php

declare(strict_types=1);

namespace App\Service;

use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;

class FileToolService
{
    /**
     * @param string $content
     * @return string
     */
    public function calculateChecksum(string $content): string
    {
        return hash('sha256', $content);
    }

    /**
     * @return array
     */
    public function getAllowedPaths(): array
    {
        $paths = explode(';', config('mcp_helpers.allowed_paths'));
        $pathsWithKeys = [];
        foreach ($paths as $key => $path) {
            $withKey = explode('|', $path);
            if (count($withKey) === 2) {
                $paths[$key] = $withKey[0];
                $pathsWithKeys[$withKey[0]] = $withKey[1];
                continue;
            }

            $pathsWithKeys[] = $withKey[0];
        }

        return $pathsWithKeys;
    }

    /**
     * @param array $lines
     * @param int $startLine
     * @param int $endLine
     * @return array
     */
    public function deleteLines(array $lines, int $startLine, int $endLine): array
    {
        // Convert to 0-indexed
        $start = $startLine - 1;
        $end = $endLine - 1;

        // Calculate how many lines to remove
        $removeCount = $end - $start + 1;

        // Delete lines
        array_splice($lines, $start, $removeCount);

        return $lines;
    }

    /**
     * @param array $lines
     * @param int $lineNumber
     * @param $content
     * @return array
     */
    public function insertLines(array $lines, int $lineNumber, $content): array
    {
        // Convert to 0-indexed
        $index = $lineNumber - 1;

        // Ensure content is an array
        $contentLines = is_array($content) ? $content : [$content];

        // Insert before the specified line
        array_splice($lines, $index, 0, $contentLines);

        return $lines;
    }

    /**
     * @param array $lines
     * @param $content
     * @return array
     */
    public function appendLines(array $lines, $content): array
    {
        // Ensure content is an array
        $contentLines = is_array($content) ? $content : [$content];

        // Append to end
        return array_merge($lines, $contentLines);
    }

    /**
     * @param array $allowedPaths
     * @param string $path
     * @return bool
     */
    public function isPathAllowed(array $allowedPaths, string $path): bool
    {
        // Get the real absolute path (resolves symlinks and relative paths)
        $realPath = realpath($path);

        // If path doesn't exist, check parent directory
        if ($realPath === false) {
            $parentDir = dirname($path);
            $realParentPath = realpath($parentDir);

            if ($realParentPath === false) {
                return false;
            }

            // Reconstruct the full path with the filename
            $realPath = $realParentPath . DIRECTORY_SEPARATOR . basename($path);
        }

        // Check if the real path starts with any of the allowed paths
        foreach ($allowedPaths as $allowedPath) {
            $realAllowedPath = realpath($allowedPath);

            if ($realAllowedPath === false) {
                continue;
            }

            $realAllowedPath = rtrim($realAllowedPath, DIRECTORY_SEPARATOR);

            if (str_starts_with($realPath, $realAllowedPath . DIRECTORY_SEPARATOR) ||
                $realPath === $realAllowedPath) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param int $perms
     * @return string
     */
    public function formatPermissions(int $perms): string
    {
        // File type
        $type = '';
        if (($perms & 0xC000) == 0xC000) {
            $type = 's'; // Socket
        } elseif (($perms & 0xA000) == 0xA000) {
            $type = 'l'; // Symbolic Link
        } elseif (($perms & 0x8000) == 0x8000) {
            $type = '-'; // Regular
        } elseif (($perms & 0x6000) == 0x6000) {
            $type = 'b'; // Block special
        } elseif (($perms & 0x4000) == 0x4000) {
            $type = 'd'; // Directory
        } elseif (($perms & 0x2000) == 0x2000) {
            $type = 'c'; // Character special
        } elseif (($perms & 0x1000) == 0x1000) {
            $type = 'p'; // FIFO pipe
        } else {
            $type = 'u'; // Unknown
        }

        // Owner
        $owner = '';
        $owner .= (($perms & 0x0100) ? 'r' : '-');
        $owner .= (($perms & 0x0080) ? 'w' : '-');
        $owner .= (($perms & 0x0040) ?
            (($perms & 0x0800) ? 's' : 'x' ) :
            (($perms & 0x0800) ? 'S' : '-'));

        // Group
        $group = '';
        $group .= (($perms & 0x0020) ? 'r' : '-');
        $group .= (($perms & 0x0010) ? 'w' : '-');
        $group .= (($perms & 0x0008) ?
            (($perms & 0x0400) ? 's' : 'x' ) :
            (($perms & 0x0400) ? 'S' : '-'));

        // Other
        $other = '';
        $other .= (($perms & 0x0004) ? 'r' : '-');
        $other .= (($perms & 0x0002) ? 'w' : '-');
        $other .= (($perms & 0x0001) ?
            (($perms & 0x0200) ? 't' : 'x' ) :
            (($perms & 0x0200) ? 'T' : '-'));

        return $type . $owner . $group . $other;
    }

    /**
     * @param string $filePath
     * @return bool
     */
    public function isBinaryByExtension(string $filePath): bool
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        // Common text file extensions
        $textExtensions = [
            'txt', 'md', 'php', 'js', 'json', 'xml', 'html', 'css', 'scss', 'sass',
            'yml', 'yaml', 'ini', 'conf', 'config', 'log', 'sql', 'sh', 'bash',
            'py', 'rb', 'java', 'c', 'cpp', 'h', 'go', 'rs', 'ts', 'jsx', 'tsx',
            'vue', 'svelte', 'env', 'gitignore', 'dockerignore', 'editorconfig'
        ];

        return !in_array($extension, $textExtensions, true);
    }

    public function isBinaryFile(string $filePath): bool
    {
        // Use finfo to detect MIME type
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            // Fallback: check file extension if finfo fails
            return $this->isBinaryByExtension($filePath);
        }

        $mimeType = finfo_file($finfo, $filePath);
        finfo_close($finfo);

        if ($mimeType === false) {
            // Fallback: check file extension if mime detection fails
            return $this->isBinaryByExtension($filePath);
        }

        // Consider file binary if it's not a text type
        return !str_starts_with($mimeType, 'text/') &&
            $mimeType !== 'application/json' &&
            $mimeType !== 'application/xml' &&
            $mimeType !== 'application/javascript' &&
            $mimeType !== 'application/x-httpd-php' &&
            $mimeType !== 'application/x-sh';
    }
    public function searchInFiles(array $files, string $contentQuery, bool $caseInsensitive, ?int $contextLines, ?int $maxResults)
    {

    }

    /**
     * Read file and prepare results with 1-based line numbering
     */
    public function readFileAndPrepareResults(string $pathAndFilename): array
    {
        $contents = file_get_contents($pathAndFilename);
        $lines = explode("\n", $contents);
        $contentsExploded = array_combine(range(1, count($lines)), $lines);

        $result = [
            'content' => $contentsExploded,
            'checksum' => $this->calculateChecksum($contents),
            'file_quick_hash' => hash(
                'xxh3',
                $pathAndFilename . ':' . filesize($pathAndFilename) . ':' . filemtime($pathAndFilename)),
        ];
        if (str_ends_with($pathAndFilename, '.php')) {
            $visitor = new ClassUsageCollector();
            $ast = (new ParserFactory())->createForNewestSupportedVersion()->parse($contents);
            $traverser = new NodeTraverser();
            $traverser->addVisitor($visitor);
            $traverser->traverse($ast);

            $result['used_classes'] = $visitor->getUsedClasses();
        }

        return $result;
    }
}
