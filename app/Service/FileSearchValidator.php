<?php

declare(strict_types=1);

namespace App\Service;

class FileSearchValidator
{
    private const MAX_LENGTH = 200;
    private const MAX_WILDCARDS = 5;
    private const ALLOWED_PATTERN = '/^[a-zA-Z0-9_\-.*\/@$ ]+$/';

    public function validateAndBuildPattern(string $userInput): string {
        $userInput = trim($userInput);

        if ($userInput === '') {
            throw new \InvalidArgumentException('Search query cannot be empty');
        }

        $this->validateSafety($userInput);
        return $this->buildRegexPattern($userInput);
    }

    private function validateSafety(string $input): void {
        // Length limit (prevent ReDoS)
        if (strlen($input) > self::MAX_LENGTH) {
            throw new \InvalidArgumentException(
                'Search query too long (max ' . self::MAX_LENGTH . ' characters)'
            );
        }

        // Wildcard limit (prevent ReDoS)
        $wildcardCount = substr_count($input, '*');
        if ($wildcardCount > self::MAX_WILDCARDS) {
            throw new \InvalidArgumentException(
                'Too many wildcards (max ' . self::MAX_WILDCARDS . ' allowed)'
            );
        }

        // No . or .. as path components
        if (preg_match('#(^|/)\.\.?($|/)#', $input)) {
            throw new \InvalidArgumentException(
                'Path components . and .. are not allowed'
            );
        }

        // No multiple consecutive slashes
        if (str_contains($input, '//')) {
            throw new \InvalidArgumentException(
                'Multiple consecutive slashes are not allowed'
            );
        }

        // Only safe characters
        if (!preg_match(self::ALLOWED_PATTERN, $input)) {
            throw new \InvalidArgumentException(
                'Only alphanumeric, underscore, hyphen, dot, slash, @, $, space and * wildcard are allowed'
            );
        }

        // No leading/trailing dots (hidden files / relative paths)
        if (preg_match('#(^|\/)\.(?!\w)|\.(?!\w)(\/)#', $input)) {
            throw new \InvalidArgumentException(
                'Dots must be part of a filename (e.g., file.php)'
            );
        }
    }

    private function buildRegexPattern(string $input): string {
        // Escape special regex characters (except *)
        $pattern = preg_quote($input, '/');

        // Convert * to non-greedy match (safer than .*)
        $pattern = str_replace('\\*', '[^/]*', $pattern);

        error_log("Pattern: " . $pattern);
        // Case-insensitive
        return '#' . $pattern . '#i';
    }
}
