<?php

namespace App\Service\ContentIndexing;

class TokenizerService
{
    private const MAX_LINE_LENGTH = 5000; // Skip very long lines
    private const MAX_TOKENS_PER_LINE = 100; // Limit tokens per line
    
    /**
     * Tokenize a line of code into searchable tokens
     */
    public function tokenizeLine(string $line, int $lineNumber): array
    {
        // Skip extremely long lines (likely generated/minified code)
        if (strlen($line) > self::MAX_LINE_LENGTH) {
            return [];
        }
        
        $tokens = [];
        
        // Step 1: Extract quoted strings first (preserve them)
        $quotedStrings = [];
        $line = preg_replace_callback('/(["\'])(?:(?=(\\\\?))\2.)*?\1/', function($matches) use (&$quotedStrings) {
            $placeholder = '___QUOTED_' . count($quotedStrings) . '___';
            $quotedStrings[$placeholder] = trim($matches[0], $matches[1]);
            return $placeholder;
        }, $line);
        
        // Step 2: Split on common delimiters while preserving symbols
        $rawTokens = preg_split('/(\s+|[(){}\[\];,])/', $line, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        
        $position = 0;
        $tokenCount = 0;
        
        foreach ($rawTokens as $rawToken) {
            if ($tokenCount >= self::MAX_TOKENS_PER_LINE) {
                break; // Stop if we've generated too many tokens for this line
            }
            
            $rawToken = trim($rawToken);
            if (empty($rawToken) || preg_match('/^\s+$/', $rawToken)) {
                $position++;
                continue;
            }
            
            // Restore quoted strings
            if (isset($quotedStrings[$rawToken])) {
                $tokens[] = [
                    'text' => $quotedStrings[$rawToken],
                    'original' => $quotedStrings[$rawToken],
                    'line' => $lineNumber,
                    'position' => $position,
                    'type' => 'string',
                    'context' => $this->extractContext($line, $position)
                ];
                $tokenCount++;
                $position++;
                continue;
            }
            
            // Step 3: Process programming constructs
            $processedTokens = $this->processToken($rawToken, $lineNumber, $position, $line);
            
            // Use array_push with spread operator instead of array_merge (much more efficient)
            array_push($tokens, ...$processedTokens);
            $tokenCount += count($processedTokens);
            
            $position++;
        }
        
        return $tokens;
    }
    
    private function processToken(string $token, int $line, int $position, string $fullLine): array
    {
        $result = [];
        
        // Skip very long tokens (likely garbage)
        if (strlen($token) > 100) {
            return [];
        }
        
        // Handle variable names ($var)
        if (str_starts_with($token, '$')) {
            $varName = substr($token, 1);
            $result[] = $this->makeToken($token, $line, $position, 'variable', $fullLine);
            
            // Also tokenize the variable name itself
            $subTokens = $this->splitCompoundWord($varName);
            foreach ($subTokens as $subToken) {
                $result[] = $this->makeToken($subToken, $line, $position, 'identifier', $fullLine);
            }
            
            return $result;
        }
        
        // Handle method calls (Class::method or $obj->method)
        if (str_contains($token, '::')) {
            [$class, $method] = explode('::', $token, 2);
            $result[] = $this->makeToken($token, $line, $position, 'method_call', $fullLine);
            $result[] = $this->makeToken($class, $line, $position, 'class', $fullLine);
            $result[] = $this->makeToken(rtrim($method, '()'), $line, $position, 'method', $fullLine);
            return $result;
        }
        
        if (str_contains($token, '->')) {
            $parts = explode('->', $token);
            $result[] = $this->makeToken($token, $line, $position, 'method_call', $fullLine);
            foreach ($parts as $part) {
                $result[] = $this->makeToken(rtrim($part, '()'), $line, $position, 'identifier', $fullLine);
            }
            return $result;
        }
        
        // Handle namespaces
        if (str_contains($token, '\\')) {
            $parts = explode('\\', $token);
            $result[] = $this->makeToken($token, $line, $position, 'namespace', $fullLine);
            foreach ($parts as $part) {
                if (!empty($part)) {
                    $result[] = $this->makeToken($part, $line, $position, 'identifier', $fullLine);
                }
            }
            return $result;
        }
        
        // Default: split compound words
        $subTokens = $this->splitCompoundWord($token);
        foreach ($subTokens as $subToken) {
            $result[] = $this->makeToken($subToken, $line, $position, 'identifier', $fullLine);
        }
        
        return $result;
    }
    
    private function splitCompoundWord(string $word): array
    {
        $result = [];
        
        // CamelCase splitting
        if (preg_match('/[a-z][A-Z]/', $word)) {
            $parts = preg_split('/(?=[A-Z])/', $word, -1, PREG_SPLIT_NO_EMPTY);
            foreach ($parts as $part) {
                $result[] = strtolower($part);
            }
        }
        
        // snake_case splitting (use array_push instead of array_merge)
        if (str_contains($word, '_')) {
            $parts = explode('_', $word);
            array_push($result, ...$parts);
            $result[] = $word; // Also keep original
        }
        
        // kebab-case splitting (use array_push instead of array_merge)
        if (str_contains($word, '-')) {
            $parts = explode('-', $word);
            array_push($result, ...$parts);
            $result[] = $word; // Also keep original
        }
        
        // If no splitting occurred, just add the word itself
        if (empty($result)) {
            $result[] = strtolower($word);
        } else {
            // Also add the complete lowercased word
            $result[] = strtolower($word);
        }
        
        return array_unique($result);
    }
    
    private function makeToken(string $text, int $line, int $position, string $type, string $context): array
    {
        return [
            'text' => strtolower($text),
            'original' => $text,
            'line' => $line,
            'position' => $position,
            'type' => $type,
            'context' => $this->extractContext($context, $position)
        ];
    }
    
    private function extractContext(string $line, int $position, int $chars = 100): string
    {
        $start = max(0, $position - $chars / 2);
        return substr($line, (int)$start, $chars);
    }
    
    /**
     * Tokenize a search query
     */
    public function tokenizeQuery(string $query): array
    {
        // Check for quoted phrases
        $tokens = [];
        
        // Extract quoted phrases first
        preg_match_all('/"([^"]+)"/', $query, $quotedMatches);
        foreach ($quotedMatches[1] as $quoted) {
            $tokens[] = strtolower($quoted);
        }
        
        // Remove quoted parts and tokenize the rest
        $query = preg_replace('/"[^"]+"/', '', $query);
        $words = preg_split('/\s+/', $query, -1, PREG_SPLIT_NO_EMPTY);
        
        foreach ($words as $word) {
            $tokens[] = strtolower(trim($word));
        }
        
        return array_unique(array_filter($tokens));
    }
}
