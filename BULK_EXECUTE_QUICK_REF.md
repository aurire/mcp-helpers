# bulk_execute - Quick Reference

## One-Line Summary
Execute multiple operations of the same tool in parallel to reduce latency by ~10x.

## Basic Usage

```php
bulk_execute(
    toolName: 'file_delete',
    operations: [
        ['pathAndFilename' => '/path/1.txt', 'fileQuickHash' => 'abc123'],
        ['pathAndFilename' => '/path/2.txt', 'fileQuickHash' => 'def456'],
    ]
)
```

## Parameters

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `toolName` | string | *required* | Name of tool to execute (e.g., 'file_delete') |
| `operations` | array[] | *required* | Array of parameter sets for each operation |
| `continueOnError` | bool | `false` | Continue executing if an operation fails |
| `parallel` | bool | `true` | Execute operations in parallel |
| `maxConcurrent` | int | `10` | Max concurrent operations (1-20) |

## Return Value

```php
[
    'success' => true,
    'succeeded' => [/* successful operations */],
    'failed' => [/* failed operations with errors */],
    'totalTime' => 0.234,  // seconds
    'executionMode' => 'parallel',
    'stats' => [
        'total' => 10,
        'succeeded' => 9,
        'failed' => 1
    ]
]
```

## Common Patterns

### Delete Multiple Files
```php
bulk_execute('file_delete', [
    ['pathAndFilename' => 'file1.txt', 'fileQuickHash' => 'hash1'],
    ['pathAndFilename' => 'file2.txt', 'fileQuickHash' => 'hash2'],
])
```

### Replace Lines Across Files
```php
bulk_execute('file_replace_line', [
    [
        'pathAndFilename' => 'a.php',
        'lineNumber' => 5,
        'newLineContent' => 'new content',
        'referenceLineContent' => 'old content',
        'fileQuickHash' => 'hash_a'
    ],
    [
        'pathAndFilename' => 'b.php',
        'lineNumber' => 10,
        'newLineContent' => 'updated',
        'referenceLineContent' => 'original',
        'fileQuickHash' => 'hash_b'
    ],
])
```

### Bulk Rename (Continue on Error)
```php
bulk_execute(
    toolName: 'file_rename',
    operations: [
        ['oldPath' => 'Old1.php', 'newPath' => 'New1.php', 'fileQuickHash' => 'h1'],
        ['oldPath' => 'Old2.php', 'newPath' => 'New2.php', 'fileQuickHash' => 'h2'],
    ],
    continueOnError: true  // Don't stop if one rename fails
)
```

## Performance Tips

1. **Use parallel mode** (default) for I/O-bound operations
2. **Tune maxConcurrent**:
   - Lower (5) for heavy operations
   - Higher (20) for light operations
   - Default (10) works for most cases
3. **Batch operations**: 100-1000 operations per call is ideal
4. **continueOnError=true** for independent operations
5. **continueOnError=false** (default) for dependent operations

## Error Handling

### Stop on First Error (Default)
```php
$result = bulk_execute('file_read', $operations);

if (!$result['success']) {
    echo "Error: " . $result['error'];
} else {
    echo "Failed: " . count($result['failed']);
}
```

### Continue Despite Errors
```php
$result = bulk_execute(
    'file_read',
    $operations,
    continueOnError: true
);

foreach ($result['failed'] as $failure) {
    echo "Operation {$failure['index']} failed: {$failure['error']}\n";
}
```

## When to Use

✅ **Good for:**
- Deleting multiple files
- Renaming multiple files
- Updating lines across files
- Creating multiple files
- Reading multiple files
- Any repeated operation

❌ **Not needed for:**
- Single operations
- Operations that are already batch-optimized
- Very fast operations (<10ms)

## Performance Example

**Before (10 sequential operations):**
```
file_delete → 200ms
file_delete → 200ms
...
Total: 2000ms (2 seconds)
```

**After (bulk_execute with 10 operations):**
```
bulk_execute → 200ms
Total: 200ms
Speedup: 10x! 🚀
```

## Troubleshooting

**Tool not found?**
```php
// Returns available tools
$result = bulk_execute('invalid_tool', []);
echo json_encode($result['availableTools']);
```

**Operation failing?**
```php
// Check error details
foreach ($result['failed'] as $failure) {
    echo "Index: {$failure['index']}\n";
    echo "Params: " . json_encode($failure['params']) . "\n";
    echo "Error: {$failure['error']}\n";
    echo "Trace: {$failure['trace']}\n";
}
```

## See Also

- Full documentation: `BULK_EXECUTE_IMPLEMENTATION.md`
- Test script: `test_bulk_execute.php`
- Tool source: `app/Mcp/Tools/Utility/BulkExecute.php`
