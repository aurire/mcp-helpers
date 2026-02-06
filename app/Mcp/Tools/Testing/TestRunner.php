<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Testing;

use App\Service\FileToolService;
use PhpMcp\Server\Attributes\McpTool;
use RuntimeException;
use Symfony\Component\Process\Process;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class TestRunner
{
    public function __construct(
        private FileToolService $fileToolService
    ) {}

    /**
     * Run PHPUnit tests with flexible filtering options
     *
     * Executes PHPUnit test suite with various filtering options and returns
     * structured results including pass/fail status, coverage, and output.
     *
     * Examples:
     * - Run all tests: test_run()
     * - Run specific test: test_run(filter: 'BulkExecuteTest')
     * - Run test method: test_run(filter: 'BulkExecuteTest::testParallelExecution')
     * - Run by path: test_run(path: 'tests/Feature/Mcp')
     * - Run with coverage: test_run(coverage: true)
     * - Run specific group: test_run(group: 'mcp')
     *
     * Parameters:
     * - path: Specific test file or directory path (relative to project root)
     * - filter: Filter tests by name (supports regex)
     * - group: Run tests from specific @group annotation
     * - coverage: Generate code coverage report (slower)
     * - stopOnFailure: Stop execution after first failure
     * - verbose: Show detailed output
     * - configuration: Path to phpunit.xml (default: phpunit.xml)
     * - projectRoot: Project root directory (auto-detected if not provided)
     *
     * Returns:
     * - success: Overall test run success
     * - summary: Test counts (tests, assertions, failures, errors, skipped)
     * - failures: Detailed failure information
     * - output: Full test output
     * - executionTime: Total time taken
     * - coverage: Coverage percentage (if coverage enabled)
     * - recommendation: Smart suggestion for next steps
     */
    #[McpTool(name: 'test_run')]
    public function testRun(
        ?string $path = null,
        ?string $filter = null,
        ?string $group = null,
        bool $coverage = false,
        bool $stopOnFailure = false,
        bool $verbose = false,
        ?string $configuration = null,
        ?string $projectRoot = null
    ): array {
        // Determine project root
        $projectRoot = $projectRoot ?? $this->detectProjectRoot();
        
        // Validate project root is allowed
        $allowedPaths = $this->fileToolService->getAllowedPaths();
        if (!$this->fileToolService->isPathAllowed($allowedPaths, $projectRoot)) {
            throw new RuntimeException("Access denied: Project root is not in allowed paths");
        }

        // Build PHPUnit command
        $command = $this->buildCommand(
            $projectRoot,
            $path,
            $filter,
            $group,
            $coverage,
            $stopOnFailure,
            $verbose,
            $configuration
        );

        // Execute tests
        $startTime = microtime(true);
        $process = Process::fromShellCommandline($command, $projectRoot, null, null, 300);
        $process->run();
        $executionTime = microtime(true) - $startTime;

        // Parse results
        $output = $process->getOutput();
        $errorOutput = $process->getErrorOutput();
        $exitCode = $process->getExitCode();

        $results = $this->parseTestOutput($output, $errorOutput, $exitCode);
        $results['executionTime'] = round($executionTime, 2);
        $results['command'] = $command;
        $results['projectRoot'] = $projectRoot;

        return $results;
    }

    /**
     * List available test files and suites
     *
     * Helps discover what tests are available to run.
     *
     * Parameters:
     * - path: Directory to scan (default: tests/)
     * - pattern: File pattern (default: *Test.php)
     * - projectRoot: Project root directory (auto-detected if not provided)
     *
     * Returns list of test files with their paths and metadata
     */
    #[McpTool(name: 'test_list')]
    public function testList(
        ?string $path = null,
        string $pattern = '*Test.php',
        ?string $projectRoot = null
    ): array {
        $projectRoot = $projectRoot ?? $this->detectProjectRoot();
        $testsPath = $path ?? $projectRoot . '/tests';

        // Validate path is allowed
        $allowedPaths = $this->fileToolService->getAllowedPaths();
        if (!$this->fileToolService->isPathAllowed($allowedPaths, $testsPath)) {
            throw new RuntimeException("Access denied: Test path is not in allowed paths");
        }

        if (!is_dir($testsPath)) {
            return [
                'success' => false,
                'error' => "Test directory not found: {$testsPath}",
                'path' => $testsPath,
            ];
        }

        $testFiles = $this->findTestFiles($testsPath, $pattern);
        
        return [
            'success' => true,
            'path' => $testsPath,
            'pattern' => $pattern,
            'count' => count($testFiles),
            'testFiles' => $testFiles,
            'projectRoot' => $projectRoot,
        ];
    }

    private function buildCommand(
        string $projectRoot,
        ?string $path,
        ?string $filter,
        ?string $group,
        bool $coverage,
        bool $stopOnFailure,
        bool $verbose,
        ?string $configuration
    ): string {
        // Start with vendor/bin/phpunit or phpunit
        $phpunit = file_exists($projectRoot . '/vendor/bin/phpunit')
            ? './vendor/bin/phpunit'
            : 'phpunit';

        $parts = [$phpunit];

        // Configuration file
        if ($configuration) {
            $parts[] = "-c {$configuration}";
        } elseif (file_exists($projectRoot . '/phpunit.xml')) {
            $parts[] = '-c phpunit.xml';
        }

        // Test path
        if ($path) {
            $parts[] = escapeshellarg($path);
        }

        // Filter by test name
        if ($filter) {
            $parts[] = '--filter ' . escapeshellarg($filter);
        }

        // Filter by group
        if ($group) {
            $parts[] = '--group ' . escapeshellarg($group);
        }

        // Coverage
        if ($coverage) {
            $parts[] = '--coverage-text';
        }

        // Stop on failure
        if ($stopOnFailure) {
            $parts[] = '--stop-on-failure';
        }

        // Verbose output
        if ($verbose) {
            $parts[] = '--verbose';
        }

        // Always use colors for better readability
        $parts[] = '--colors=always';

        return implode(' ', $parts);
    }

    private function parseTestOutput(string $output, string $errorOutput, int $exitCode): array
    {
        $success = $exitCode === 0;
        
        // Extract summary line (e.g., "Tests: 15, Assertions: 45, Failures: 2")
        $summary = $this->extractSummary($output);
        
        // Extract failure details
        $failures = $this->extractFailures($output);
        
        // Extract coverage if present
        $coverage = $this->extractCoverage($output);

        return [
            'success' => $success,
            'exitCode' => $exitCode,
            'summary' => $summary,
            'failures' => $failures,
            'coverage' => $coverage,
            'output' => $output,
            'errorOutput' => $errorOutput,
            'recommendation' => $this->generateRecommendation($success, $failures, $summary),
        ];
    }

    private function extractSummary(string $output): array
    {
        $summary = [
            'tests' => 0,
            'assertions' => 0,
            'failures' => 0,
            'errors' => 0,
            'skipped' => 0,
            'incomplete' => 0,
        ];

        // Match: "Tests: 15, Assertions: 45, Failures: 2"
        if (preg_match('/Tests: (\d+), Assertions: (\d+)/', $output, $matches)) {
            $summary['tests'] = (int)$matches[1];
            $summary['assertions'] = (int)$matches[2];
        }

        if (preg_match('/Failures: (\d+)/', $output, $matches)) {
            $summary['failures'] = (int)$matches[1];
        }

        if (preg_match('/Errors: (\d+)/', $output, $matches)) {
            $summary['errors'] = (int)$matches[1];
        }

        if (preg_match('/Skipped: (\d+)/', $output, $matches)) {
            $summary['skipped'] = (int)$matches[1];
        }

        if (preg_match('/Incomplete: (\d+)/', $output, $matches)) {
            $summary['incomplete'] = (int)$matches[1];
        }

        return $summary;
    }

    private function extractFailures(string $output): array
    {
        $failures = [];
        
        // Match failure blocks
        // Pattern: "1) TestClass::testMethod\nFailure message\nStack trace"
        if (preg_match_all('/(\d+)\) ([^\n]+)\n(.*?)(?=\n\d+\)|FAILURES!|\Z)/s', $output, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $failures[] = [
                    'number' => (int)$match[1],
                    'test' => trim($match[2]),
                    'message' => trim($match[3]),
                ];
            }
        }

        return $failures;
    }

    private function extractCoverage(string $output): ?array
    {
        if (!str_contains($output, 'Code Coverage')) {
            return null;
        }

        $coverage = [
            'lines' => null,
            'methods' => null,
            'classes' => null,
        ];

        // Match: "Lines: 85.5%"
        if (preg_match('/Lines:\s+([\d.]+)%/', $output, $matches)) {
            $coverage['lines'] = (float)$matches[1];
        }

        if (preg_match('/Methods:\s+([\d.]+)%/', $output, $matches)) {
            $coverage['methods'] = (float)$matches[1];
        }

        if (preg_match('/Classes:\s+([\d.]+)%/', $output, $matches)) {
            $coverage['classes'] = (float)$matches[1];
        }

        return $coverage;
    }

    private function generateRecommendation(bool $success, array $failures, array $summary): ?string
    {
        if ($success) {
            $testCount = $summary['tests'];
            if ($testCount === 0) {
                return '⚠️ No tests were executed. Check your filter or path parameters.';
            }
            return '✅ All tests passed! Consider running with coverage: test_run(coverage: true)';
        }

        $failureCount = $summary['failures'] + $summary['errors'];
        
        if ($failureCount === 0 && $summary['tests'] === 0) {
            return '⚠️ No tests found. Check your path or filter parameters.';
        }

        if ($failureCount === 1) {
            return '💡 TIP: One test failing. Use verbose mode for more details: test_run(verbose: true, filter: "YourTest")';
        }

        if ($failureCount <= 3) {
            return '💡 TIP: Few failures detected. Review the failure messages above and fix one at a time.';
        }

        return '⚠️ Multiple test failures. Consider --stop-on-failure to focus on first issue: test_run(stopOnFailure: true)';
    }

    private function findTestFiles(string $path, string $pattern): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && fnmatch($pattern, $file->getFilename())) {
                $relativePath = str_replace($path . '/', '', $file->getPathname());
                $files[] = [
                    'path' => $file->getPathname(),
                    'relativePath' => $relativePath,
                    'name' => $file->getFilename(),
                    'size' => $file->getSize(),
                    'modified' => $file->getMTime(),
                ];
            }
        }

        // Sort by path for consistent output
        usort($files, fn($a, $b) => strcmp($a['relativePath'], $b['relativePath']));

        return $files;
    }

    private function detectProjectRoot(): string
    {
        // Look for common Laravel/PHP project indicators
        $indicators = ['composer.json', 'phpunit.xml', 'artisan', 'vendor'];
        
        $currentDir = getcwd();
        $maxLevels = 5;
        
        for ($i = 0; $i < $maxLevels; $i++) {
            foreach ($indicators as $indicator) {
                if (file_exists($currentDir . '/' . $indicator)) {
                    return $currentDir;
                }
            }
            $currentDir = dirname($currentDir);
        }

        throw new RuntimeException('Could not detect project root. Please specify projectRoot parameter.');
    }
}
