<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Builds enhanced, user-friendly responses for file manipulation operations
 * 
 * This service implements improved UX patterns to make file operation chaining
 * easier and error recovery more intuitive for LLM interactions.
 */
class FileOperationResponseBuilder
{
    /**
     * Build an enhanced success response with prominent hash display and helpful context
     */
    public function buildSuccessResponse(
        string $pathAndFilename,
        string $operationType,
        string $newHash,
        int $lineNumber,
        string $oldContent,
        string $newContent,
        array $fullFileContent,
        int $linesAffected = 1,
        int $totalLines = 0,
        string $checksum = ''
    ): array {
        return [
            'success' => true,
            
            // MOST PROMINENT: What to use for the next operation
            'FOR_NEXT_OPERATION' => [
                'file_quick_hash' => $newHash,
                'instruction' => '⚠️  CRITICAL: Use this hash for your next operation on this file'
            ],
            
            // Operation summary
            'file' => $pathAndFilename,
            'total_lines' => $totalLines,
            'operation_summary' => [
                'type' => $operationType,
                'lines_affected' => $linesAffected,
                'line_number' => $lineNumber,
                'old_content' => $oldContent,
                'new_content' => $newContent
            ],
            
            // Context for next operations - extract adjacent lines
            'helpful_context' => [
                'tip' => '💡 Copy these EXACT strings (including whitespace) for referenceLineContent in your next operation',
                'adjacent_lines' => $this->getAdjacentLines($fullFileContent['content'] ?? $fullFileContent, $lineNumber, 2)
            ],
            
            // Legacy fields for backward compatibility
            'line_number' => $lineNumber,
            'old_content' => $oldContent,
            'new_content' => $newContent,
            'replaced_lines' => $linesAffected,
            'checksum' => $checksum,
            'file_quick_hash' => $newHash,
            
            // Full file for reference
            'new_file' => $fullFileContent
        ];
    }

    /**
     * Build an enhanced error response for hash mismatch
     */
    public function buildHashMismatchError(
        string $expectedHash,
        string $actualHash,
        string $pathAndFilename
    ): array {
        return [
            'success' => false,
            'error_type' => 'hash_mismatch',
            'error' => 'File has changed since last read',
            
            'details' => [
                'expected_hash' => $expectedHash,
                'current_hash' => $actualHash,
                'file' => $pathAndFilename
            ],
            
            'suggestion' => 'The file was modified after you read it. To fix this, use the current_hash shown above for your next operation, or call file_read to get the latest file state.',
            
            'recovery_steps' => [
                "1. Call file_read('{$pathAndFilename}') to get the current file state",
                "2. Use the returned file_quick_hash: '{$actualHash}'",
                "3. Re-attempt your operation with the new hash"
            ],
            
            'tip' => '💡 Each successful file operation returns a new hash. Always chain operations using the hash from the most recent response.'
        ];
    }

    /**
     * Build an enhanced error response for reference line mismatch
     */
    public function buildReferenceLineMismatchError(
        int $lineNumber,
        string $providedContent,
        string $actualContent,
        string $pathAndFilename,
        array $lines
    ): array {
        // Truncate long lines for display
        $providedDisplay = strlen($providedContent) > 60 
            ? substr($providedContent, 0, 60) . '...' 
            : $providedContent;
        $actualDisplay = strlen($actualContent) > 60 
            ? substr($actualContent, 0, 60) . '...' 
            : $actualContent;

        return [
            'success' => false,
            'error_type' => 'reference_line_mismatch',
            'error' => 'Reference line content doesn\'t match',
            
            'details' => [
                'line_number' => $lineNumber,
                'file' => $pathAndFilename,
                'you_provided' => $providedDisplay,
                'actual_content' => $actualDisplay,
                'full_provided' => $providedContent,
                'full_actual' => $actualContent
            ],
            
            'suggestion' => 'The line content doesn\'t match what\'s currently in the file. This usually means the file was modified, or there are whitespace differences.',
            
            'what_to_do' => [
                '1. Check for extra/missing whitespace in your referenceLineContent',
                '2. Verify you\'re using the correct line number',
                '3. Call file_read to see the current file state',
                "4. Use this EXACT content for line {$lineNumber}: '{$actualContent}'"
            ],
            
            'nearby_lines' => $this->getAdjacentLines($lines, $lineNumber, 3),
            
            'tip' => '💡 Copy line content EXACTLY from \'nearby_lines\' including all spaces, tabs, and indentation'
        ];
    }

    /**
     * Get adjacent lines for context
     * 
     * @param array $lines Array of lines (can be 0-indexed or 1-indexed)
     * @param int $centerLine The line number (1-based)
     * @param int $context Number of lines before/after to include
     * @return array Associative array of line_number => content
     */
    private function getAdjacentLines(array $lines, int $centerLine, int $context = 2): array
    {
        $result = [];
        
        // Check if array is 1-indexed (has key 1) or 0-indexed
        $is1Indexed = isset($lines[1]) && !isset($lines[0]);
        
        if ($is1Indexed) {
            // Array is already 1-indexed (like from readFileAndPrepareResults)
            $start = max(1, $centerLine - $context);
            $end = min(array_key_last($lines), $centerLine + $context);
            
            for ($i = $start; $i <= $end; $i++) {
                if (isset($lines[$i])) {
                    $result[$i] = $lines[$i];
                }
            }
        } else {
            // Array is 0-indexed, need to convert
            $start = max(0, $centerLine - $context - 1);
            $end = min(count($lines) - 1, $centerLine + $context - 1);
            
            for ($i = $start; $i <= $end; $i++) {
                if (isset($lines[$i])) {
                    // Convert to 1-based line numbers for display
                    $result[$i + 1] = $lines[$i];
                }
            }
        }
        
        return $result;
    }

    /**
     * Build response for insert operation
     */
    public function buildInsertResponse(
        string $pathAndFilename,
        string $newHash,
        int $lineNumber,
        string $insertPosition,
        int $insertedLinesCount,
        int $totalLines,
        string $checksum,
        array $fullFileContent
    ): array {
        return [
            'success' => true,
            
            'FOR_NEXT_OPERATION' => [
                'file_quick_hash' => $newHash,
                'instruction' => '⚠️  CRITICAL: Use this hash for your next operation on this file'
            ],
            
            'file' => $pathAndFilename,
            'total_lines' => $totalLines,
            
            'operation_summary' => [
                'type' => 'line_insertion',
                'lines_affected' => $insertedLinesCount,
                'reference_line' => $lineNumber,
                'insert_position' => $insertPosition
            ],
            
            'helpful_context' => [
                'tip' => '💡 Copy these EXACT strings (including whitespace) for referenceLineContent in your next operation',
                'adjacent_lines' => $this->getAdjacentLines($fullFileContent['content'] ?? $fullFileContent, $lineNumber, 3)
            ],
            
            // Legacy fields
            'line_number' => $lineNumber,
            'insert_position' => $insertPosition,
            'inserted_lines' => $insertedLinesCount,
            'checksum' => $checksum,
            'file_quick_hash' => $newHash,
            
            'new_file' => $fullFileContent
        ];
    }

    /**
     * Build response for delete operation
     */
    public function buildDeleteResponse(
        string $pathAndFilename,
        string $newHash,
        int $startLine,
        int $endLine,
        int $deletedLinesCount,
        int $totalLines,
        string $checksum,
        array $fullFileContent
    ): array {
        // For helpful context, show lines around where the deletion occurred
        $contextLine = $startLine > 1 ? $startLine - 1 : 1;
        
        return [
            'success' => true,
            
            'FOR_NEXT_OPERATION' => [
                'file_quick_hash' => $newHash,
                'instruction' => '⚠️  CRITICAL: Use this hash for your next operation on this file'
            ],
            
            'file' => $pathAndFilename,
            'total_lines' => $totalLines,
            
            'operation_summary' => [
                'type' => 'line_deletion',
                'lines_affected' => $deletedLinesCount,
                'start_line' => $startLine,
                'end_line' => $endLine
            ],
            
            'helpful_context' => [
                'tip' => '💡 Lines were deleted. These are the lines near where the deletion occurred',
                'adjacent_lines' => $this->getAdjacentLines($fullFileContent['content'] ?? $fullFileContent, $contextLine, 3)
            ],
            
            // Legacy fields
            'start_line' => $startLine,
            'end_line' => $endLine,
            'deleted_lines' => $deletedLinesCount,
            'checksum' => $checksum,
            'file_quick_hash' => $newHash,
            
            'new_file' => $fullFileContent
        ];
    }
}
