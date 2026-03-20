<?php

namespace Kekser\LaravelPaladin\Ai\Simple\Agents;

use Illuminate\Support\Facades\Log;
use Kekser\LaravelPaladin\Ai\Concerns\InteractsWithLogEntries;

/**
 * Analyzes log entries to extract structured issue information without using AI.
 */
class IssueAnalyzer
{
    use InteractsWithLogEntries;

    /**
     * Analyze log entries and return structured issue information.
     *
     * @param  array  $logEntries  Array of log entry data
     * @return array Array of issue objects
     */
    public function analyze(array $logEntries): array
    {
        Log::info('[Paladin] Analyzing issues with Simple evaluator (no AI)');

        $issues = [];

        foreach ($logEntries as $entry) {
            $issue = $this->extractIssueFromLogEntry($entry);

            if ($issue === null) {
                continue;
            }

            // Check if this is a duplicate issue
            $duplicateIndex = $this->findDuplicateIssue($issues, $issue);

            if ($duplicateIndex !== null) {
                // Merge with existing issue
                $issues[$duplicateIndex] = $this->mergeIssues($issues[$duplicateIndex], $issue);
            } else {
                $issues[] = $issue;
            }
        }

        return $issues;
    }

    /**
     * Extract an issue from a single log entry.
     *
     * @param  array  $logEntry  Single log entry
     * @return array|null Extracted issue or null if not applicable
     */
    protected function extractIssueFromLogEntry(array $logEntry): ?array
    {
        $message = $logEntry['message'] ?? '';
        $stackTrace = $logEntry['stack_trace'] ?? '';
        $level = $logEntry['level'] ?? 'error';

        // Skip entries without messages
        if (empty($message)) {
            return null;
        }

        // Determine issue type
        $type = $this->extractType($message, $stackTrace);

        // Determine severity
        $severity = $this->determineSeverity($level, $type, $message);

        // Extract affected files
        $affectedFiles = $this->extractAffectedFiles($stackTrace, $message);

        // Generate suggested fix
        $suggestedFix = $this->generateSuggestedFix($type, $message);

        // Generate ID based on error signature
        $id = $this->generateIssueId($type, $affectedFiles);

        return [
            'id' => $id,
            'type' => $type,
            'severity' => $severity,
            'title' => $this->generateTitle($type, $message),
            'message' => $message,
            'stack_trace' => $stackTrace,
            'affected_files' => $affectedFiles,
            'suggested_fix' => $suggestedFix,
            'log_level' => $level,
        ];
    }

    /**
     * Extract the error type from message and stack trace.
     */
    protected function extractType(string $message, string $stackTrace): string
    {
        // Check for specific error types first
        if (preg_match('/(TypeError|ParseError|FatalError|ArgumentCountError)/i', $message, $matches)) {
            return $matches[1];
        }

        // Check for database errors (before namespaced patterns to override QueryException -> DatabaseException)
        if (preg_match('/(QueryException|PDOException|DatabaseException)/i', $message, $matches)) {
            return 'DatabaseException';
        }

        // Check for namespaced exception classes (e.g., Illuminate\Auth\AuthenticationException:)
        // Match full namespaced class and extract just the class name
        if (preg_match('/([A-Za-z0-9_]+\\\\[A-Za-z0-9_]+\\\\[A-Za-z0-9_]+Exception)\s*:/', $message, $matches)) {
            // Extract just the class name without namespace
            $parts = explode('\\', $matches[1]);

            return end($parts);
        }

        // Check for two-level namespace (e.g., Illuminate\Exception:)
        if (preg_match('/([A-Za-z0-9_]+\\\\[A-Za-z0-9_]+Exception)\s*:/', $message, $matches)) {
            $parts = explode('\\', $matches[1]);

            return end($parts);
        }

        // Check for simple exception classes (ClassNameException:)
        if (preg_match('/(\w+Exception)\s*:/', $message, $matches)) {
            return $matches[1];
        }

        // Check for namespaced exception classes without colon
        if (preg_match('/([A-Za-z0-9_]+\\\\[A-Za-z0-9_]+Exception)(?:\s|$)/', $message, $matches)) {
            $parts = explode('\\', $matches[1]);

            return end($parts);
        }

        // Check for PHP error types with Error suffix
        if (preg_match('/([A-Za-z]+Error)\s*:/', $message, $matches)) {
            return $matches[1];
        }

        // Check for PHP errors
        if (preg_match('/PHP\s+\w+\s+(Error|Warning|Notice|Deprecated)/i', $message, $matches)) {
            return $matches[1];
        }

        // Check for HTTP errors
        if (preg_match('/HTTP\s+(\d+)/i', $message, $matches)) {
            return 'HttpException';
        }

        // Check for validation errors
        if (preg_match('/ValidationException|Validation\s+failed/i', $message, $matches)) {
            return 'ValidationException';
        }

        // Check stack trace for namespaced exception type
        if (preg_match('/([A-Za-z0-9_]+\\\\[A-Za-z0-9_]+Exception)[\s:]/', $stackTrace, $matches)) {
            $parts = explode('\\', $matches[1]);

            return end($parts);
        }

        // Check for simple exception in stack trace
        if (preg_match('/(\w+Exception)[\s:]/', $stackTrace, $matches)) {
            return $matches[1];
        }

        // Check for PHP error types
        if (preg_match('/(ParseError|TypeError|FatalError|Error)/', $stackTrace, $matches)) {
            return $matches[1];
        }

        // Default type
        return 'UnknownError';
    }

    /**
     * Determine severity based on log level and error type.
     */
    protected function determineSeverity(string $level, string $type, string $message): string
    {
        // Critical level always critical
        if (in_array(strtolower($level), ['critical', 'emergency', 'alert'])) {
            return 'critical';
        }

        // Check for critical error types
        $criticalTypes = [
            'FatalError', 'OutOfMemoryError', 'ParseError',
            'DatabaseException', 'ConnectionException', 'ServerException',
        ];

        foreach ($criticalTypes as $criticalType) {
            if ($type === $criticalType) {
                return 'critical';
            }
        }

        // Check for critical messages
        $criticalPatterns = [
            '/connection\s+refused/i',
            '/out\s+of\s+memory/i',
            '/memory\s+limit/i',
            '/fatal\s+error/i',
            '/segmentation\s+fault/i',
        ];

        foreach ($criticalPatterns as $pattern) {
            if (preg_match($pattern, $message)) {
                return 'critical';
            }
        }

        // High severity
        if (str_contains($type, 'Exception') || in_array(strtolower($level), ['error'])) {
            return 'high';
        }

        // Medium severity
        if (in_array(strtolower($level), ['warning'])) {
            return 'medium';
        }

        // Low severity
        return 'low';
    }

    /**
     * Extract affected files from stack trace and message.
     */
    protected function extractAffectedFiles(string $stackTrace, string $message): array
    {
        $files = [];

        // Extract from stack trace (Laravel format: #N {path}({line}): ...)
        if (preg_match_all('/[#\/]\d+\s+(.+?)\((\d+)\)/', $stackTrace, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $file = $match[1];
                // Only include project files (exclude vendor)
                if (! str_contains($file, 'vendor/') && ! str_contains($file, 'vendor\\')) {
                    $files[] = $file;
                }
            }
        }

        // Extract from standard stack trace
        if (preg_match_all('/in\s+(.+?)\s*on\s*line\s*(\d+)/i', $stackTrace, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $file = $match[1];
                if (! str_contains($file, 'vendor/') && ! str_contains($file, 'vendor\\')) {
                    $files[] = $file;
                }
            }
        }

        // Extract from message
        if (preg_match('/in\s+(.+?)\s*:\s*(\d+)/i', $message, $match)) {
            $file = $match[1];
            if (! str_contains($file, 'vendor/') && ! str_contains($file, 'vendor\\')) {
                $files[] = $file;
            }
        }

        // Remove duplicates and limit to reasonable number
        $files = array_unique($files);
        $files = array_slice($files, 0, 10);

        return array_values($files);
    }

    /**
     * Generate a suggested fix based on error type.
     */
    protected function generateSuggestedFix(string $type, string $message): string
    {
        $suggestions = [
            'UndefinedVariable' => 'Define the missing variable or check for typos in variable names.',
            'UndefinedIndex' => 'Check if the array key exists before accessing it using isset() or null coalescing operator (??).',
            'UndefinedMethod' => 'Verify the method name and ensure it exists in the class. Check inheritance and traits.',
            'UndefinedProperty' => 'Ensure the property is declared in the class or use __get/__set magic methods if dynamic.',
            'TypeError' => 'Check function/method signatures for correct parameter types and return types.',
            'ArgumentCountError' => 'Check the number of arguments passed to functions/methods.',
            'ParseError' => 'Check for syntax errors like missing semicolons, brackets, or quotes.',
            'FatalError' => 'Review the stack trace to identify the root cause. Check for recursive calls or infinite loops.',
            'DatabaseException' => 'Check database connection settings, query syntax, and table/field existence.',
            'QueryException' => 'Review the SQL query for syntax errors and check database schema.',
            'HttpException' => 'Check route definitions and ensure the requested resource exists.',
            'NotFoundHttpException' => 'Verify the route exists and the URL is correct.',
            'MethodNotAllowedHttpException' => 'Check that the HTTP method matches the route definition.',
            'ValidationException' => 'Review validation rules and ensure input data meets the requirements.',
            'AccessDeniedHttpException' => 'Check user permissions and authorization policies.',
            'AuthenticationException' => 'Verify authentication credentials and token validity.',
            'TokenMismatchException' => 'Check CSRF token configuration and form submission.',
            'ModelNotFoundException' => 'Verify the model ID exists in the database.',
            'FileNotFoundException' => 'Ensure the file exists at the specified path and check file permissions.',
            'MissingMandatoryParametersException' => 'Check that all required route parameters are provided.',
            'BindingResolutionException' => 'Verify dependency injection bindings in service providers.',
            'ReflectionException' => 'Check that the referenced class exists and is autoloaded.',
        ];

        // Check for specific error patterns in type
        foreach ($suggestions as $errorType => $suggestion) {
            if (str_contains($type, $errorType)) {
                return $suggestion;
            }
        }

        // Check for error patterns in the message
        $messagePatterns = [
            'Undefined variable' => 'UndefinedVariable',
            'Undefined index' => 'UndefinedIndex',
            'Undefined offset' => 'UndefinedIndex',
            'Undefined method' => 'UndefinedMethod',
            'Call to undefined method' => 'UndefinedMethod',
            'Undefined property' => 'UndefinedProperty',
            'Trying to get property' => 'UndefinedProperty',
        ];

        foreach ($messagePatterns as $pattern => $errorType) {
            if (str_contains($message, $pattern)) {
                return $suggestions[$errorType] ?? $suggestions['UndefinedVariable'];
            }
        }

        // Default suggestion
        return 'Review the error message and stack trace to identify the root cause. Check for syntax errors, missing files, or incorrect method calls.';
    }

    /**
     * Generate a title for the issue.
     */
    protected function generateTitle(string $type, string $message): string
    {
        // Extract key info from message (first sentence within 80 chars, or first 80 chars)
        $periodPos = strpos($message, '.');
        if ($periodPos !== false && $periodPos < 80) {
            $title = substr($message, 0, $periodPos + 1);
        } else {
            $title = substr($message, 0, 80);
        }

        // Clean up and truncate if needed (without the type prefix)
        $title = trim($title);
        if (strlen($title) >= 70) {
            $title = substr($title, 0, 67).'...';
        }

        return $type.': '.$title;
    }

    /**
     * Generate a unique ID for the issue.
     */
    protected function generateIssueId(string $type, array $affectedFiles): string
    {
        $signature = $type;
        if (! empty($affectedFiles)) {
            $signature .= ':'.reset($affectedFiles);
        }

        return md5($signature);
    }

    /**
     * Find if an issue already exists with the same signature.
     */
    protected function findDuplicateIssue(array $issues, array $newIssue): ?int
    {
        foreach ($issues as $index => $issue) {
            if ($issue['id'] === $newIssue['id']) {
                return $index;
            }
        }

        return null;
    }

    /**
     * Merge duplicate issues, combining affected files.
     */
    protected function mergeIssues(array $existingIssue, array $newIssue): array
    {
        // Merge affected files
        $mergedFiles = array_unique(array_merge(
            $existingIssue['affected_files'] ?? [],
            $newIssue['affected_files'] ?? []
        ));

        $existingIssue['affected_files'] = array_values(array_slice($mergedFiles, 0, 10));

        return $existingIssue;
    }
}
