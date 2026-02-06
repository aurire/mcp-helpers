# MCP File Operations Tools - Reference

## New Tools (Phase 1)

### File Operations

#### file_delete
Delete a file with optimistic locking
```php
file_delete(
    pathAndFilename: string,  // Path to file to delete
    fileQuickHash: string      // Hash from file_read to verify file hasn't changed
)
```

#### file_rename
Rename or move a file with optimistic locking
```php
file_rename(
    oldPath: string,          // Current file path
    newPath: string,          // New file path
    fileQuickHash: string     // Hash from file_read to verify file hasn't changed
)
```

### Directory Operations

#### file_create_directory
Create a new directory
```php
file_create_directory(
    path: string,             // Directory path to create
    recursive: bool = true,   // Create parent directories if needed
    mode: int = 0755          // Unix permissions
)
```

#### file_list_directory
List directory contents
```php
file_list_directory(
    path: string,             // Directory to list
    recursive: bool = false,  // Include subdirectories
    pattern: string|null = null  // Glob pattern filter (e.g., "*.php", "Test*")
)
```

### Inspection

#### file_exists
Check if path exists and get its type
```php
file_exists(
    path: string              // Path to check
)

// Returns:
[
    'exists' => bool,
    'type' => 'file' | 'directory' | 'symlink' | null
]
```

## Design Principles

1. **Optimistic Locking** - All file mutation operations require `fileQuickHash` verification
2. **No Directory Hashes** - Directories don't have content hashes
3. **Safety First** - Comprehensive validation prevents accidental operations
4. **Auto-Indexing** - Search index automatically updated on file operations
5. **Path Security** - All operations validate against allowed directory list

## Next Phase: Generic Bulk Operations

### Proposed: bulk_execute
```php
bulk_execute(
    toolName: string,              // Name of tool to execute in bulk
    operations: array[],           // Array of parameter sets
    continueOnError: bool = false, // Continue on partial failure
    parallel: bool = true          // Execute in parallel if possible
)

// Example: Bulk delete
bulk_execute('file_delete', [
    ['pathAndFilename' => '/path/1.tmp', 'fileQuickHash' => 'hash1'],
    ['pathAndFilename' => '/path/2.tmp', 'fileQuickHash' => 'hash2'],
])

// Returns:
[
    'succeeded' => array[],    // Successful operations
    'failed' => array[],       // Failed operations with errors
    'totalTime' => float
]
```

This generic wrapper will work with ANY existing tool, providing:
- Parallel execution for I/O-bound operations
- Partial success handling
- Detailed error reporting per operation
- Significant latency reduction for AI agents

## Usage Tips

1. **Always use file_read first** to get the `fileQuickHash` before delete/rename operations
2. **Use file_exists** before creating files/directories to check for conflicts
3. **Use glob patterns** in file_list_directory to filter large directories
4. **Prefer recursive: true** for file_create_directory unless you need strict parent validation
5. **Bulk operations coming soon** - will dramatically reduce round-trip latency for multiple operations
