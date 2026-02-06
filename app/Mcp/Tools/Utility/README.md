# Utility Tools

This directory contains utility MCP tools that enhance the functionality of other tools.

## Available Tools

### bulk_execute

Execute multiple operations of the same tool in parallel, dramatically reducing latency.

**Performance:** ~10x faster for batch operations

**Quick Example:**
```php
bulk_execute(
    toolName: 'file_delete',
    operations: [
        ['pathAndFilename' => '/path/1.txt', 'fileQuickHash' => 'hash1'],
        ['pathAndFilename' => '/path/2.txt', 'fileQuickHash' => 'hash2'],
        // ... more operations
    ]
)
```

**Documentation:**
- Quick Reference: `/BULK_EXECUTE_QUICK_REF.md`
- Full Documentation: `/BULK_EXECUTE_IMPLEMENTATION.md`
- Test Script: `/test_bulk_execute.php`

**Use Cases:**
- Bulk file operations (delete, rename, create, read)
- Batch line operations (replace, insert, delete)
- Multiple memory operations
- Any repeated operation on different inputs

**Key Features:**
- ✅ Works with ANY existing MCP tool
- ✅ Parallel execution (configurable concurrency)
- ✅ Robust error handling (continue or stop on error)
- ✅ Detailed success/failure reporting
- ✅ Automatic tool validation

## Adding New Utility Tools

To add a new utility tool:

1. Create a new PHP class in this directory
2. Add the `#[McpTool]` attribute to your method
3. The tool will be automatically discovered by the MCP server

Example:
```php
<?php

namespace App\Mcp\Tools\Utility;

use PhpMcp\Server\Attributes\McpTool;

class MyUtilityTool
{
    #[McpTool(name: 'my_utility_tool')]
    public function execute(string $param): array
    {
        // Your implementation
        return ['success' => true];
    }
}
```

## Philosophy

Utility tools should:
- **Enhance existing tools** - Add capabilities without duplicating code
- **Be generic** - Work with multiple tools or scenarios
- **Be efficient** - Improve performance or reduce complexity
- **Be well-documented** - Clear examples and use cases
