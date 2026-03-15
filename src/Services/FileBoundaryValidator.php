<?php

namespace Kekser\LaravelPaladin\Services;

use Illuminate\Support\Facades\File;

class FileBoundaryValidator
{
    protected array $excludedPaths;

    protected array $allowedPaths;

    protected array $gitignorePatterns;

    protected string $basePath;

    public function __construct()
    {
        $this->basePath = base_path();

        // Load excluded paths from config (vendor/, node_modules/, etc.)
        $this->excludedPaths = config('paladin.file_boundaries.excluded_paths', [
            'vendor/',
            'node_modules/',
        ]);

        // Load optional allowed paths from config
        $this->allowedPaths = config('paladin.file_boundaries.allowed_paths', []);

        // Parse .gitignore patterns
        $this->gitignorePatterns = $this->parseGitignore();
    }

    /**
     * Determine if a file path is within project boundaries (fixable).
     */
    public function isProjectFile(string $filePath): bool
    {
        // Normalize path (remove leading /, handle relative paths)
        $filePath = $this->normalizePath($filePath);

        // Check if file is in allowed_paths (exception list that overrides exclusions)
        // If it matches, skip all exclusion checks
        $isExplicitlyAllowed = false;
        if (! empty($this->allowedPaths)) {
            foreach ($this->allowedPaths as $allowed) {
                if (str_starts_with($filePath, $allowed)) {
                    $isExplicitlyAllowed = true;
                    break;
                }
            }
        }

        // If explicitly allowed, skip all exclusion checks
        if ($isExplicitlyAllowed) {
            return true;
        }

        // Check explicitly excluded paths (vendor/, node_modules/)
        // These can appear anywhere in the path
        foreach ($this->excludedPaths as $excluded) {
            // Remove trailing slash for consistent matching
            $excluded = rtrim($excluded, '/');

            // Check if path starts with excluded path (e.g., "vendor/...")
            if (str_starts_with($filePath, $excluded.'/')) {
                return false;
            }

            // Check if excluded path appears in the middle (e.g., ".../vendor/...")
            if (str_contains($filePath, '/'.$excluded.'/')) {
                return false;
            }
        }

        // Check .gitignore patterns
        foreach ($this->gitignorePatterns as $pattern) {
            if ($this->matchesGitignorePattern($filePath, $pattern)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Analyze an issue's affected files to determine if it's fixable.
     */
    public function analyzeIssue(array $affectedFiles): array
    {
        $internalFiles = [];
        $externalFiles = [];

        foreach ($affectedFiles as $file) {
            if ($this->isProjectFile($file)) {
                $internalFiles[] = $file;
            } else {
                $externalFiles[] = $file;
            }
        }

        // Fixable if AT LEAST ONE file is internal
        $isFixable = count($internalFiles) > 0;

        $reason = null;
        if (! $isFixable) {
            if (count($affectedFiles) === 0) {
                $reason = 'No affected files identified in stack trace';
            } else {
                $externalPaths = implode(', ', array_slice($externalFiles, 0, 3));
                if (count($externalFiles) > 3) {
                    $externalPaths .= ' and '.(count($externalFiles) - 3).' more';
                }
                $reason = 'All affected files are outside project boundaries: '.$externalPaths;
            }
        }

        return [
            'is_fixable' => $isFixable,
            'reason' => $reason,
            'internal_files' => $internalFiles,
            'external_files' => $externalFiles,
        ];
    }

    /**
     * Extract file paths from a stack trace string.
     */
    public function extractFilesFromStackTrace(string $stackTrace): array
    {
        $files = [];

        // Match common Laravel stack trace patterns:
        // 1. "/path/to/file.php:123"
        // 2. "in /path/to/file.php on line 123"
        // 3. "#0 /path/to/file.php(123)"

        // Pattern 1 & 3: /path/to/file.php:123 or /path/to/file.php(123)
        if (preg_match_all('#([/\w\-\.]+\.php)[:(](\d+)#', $stackTrace, $matches)) {
            foreach ($matches[1] as $file) {
                $files[] = $file;
            }
        }

        // Pattern 2: in /path/to/file.php on line 123
        if (preg_match_all('#in ([/\w\-\.]+\.php) on line \d+#', $stackTrace, $matches)) {
            foreach ($matches[1] as $file) {
                $files[] = $file;
            }
        }

        // Remove duplicates and normalize paths
        $files = array_unique($files);

        return array_map([$this, 'normalizePath'], $files);
    }

    /**
     * Normalize a file path for comparison.
     */
    protected function normalizePath(string $filePath): string
    {
        // Remove leading slash
        $filePath = ltrim($filePath, '/');

        // If path starts with base_path(), remove it
        if (str_starts_with($filePath, $this->basePath)) {
            $filePath = substr($filePath, strlen($this->basePath));
            $filePath = ltrim($filePath, '/');
        }

        // Remove any remaining leading ./
        $filePath = ltrim($filePath, './');

        return $filePath;
    }

    /**
     * Parse .gitignore file and return patterns.
     */
    protected function parseGitignore(): array
    {
        $gitignorePath = base_path('.gitignore');

        if (! File::exists($gitignorePath)) {
            return [];
        }

        $content = File::get($gitignorePath);
        $lines = explode("\n", $content);

        $patterns = [];
        foreach ($lines as $line) {
            $line = trim($line);

            // Skip empty lines and comments
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            // Remove negation patterns (we don't support them yet)
            if (str_starts_with($line, '!')) {
                continue;
            }

            $patterns[] = $line;
        }

        return $patterns;
    }

    /**
     * Check if a file path matches a .gitignore pattern.
     */
    protected function matchesGitignorePattern(string $filePath, string $pattern): bool
    {
        // Remove trailing slash from pattern
        $pattern = rtrim($pattern, '/');

        // Handle directory patterns (e.g., "build/")
        $isDirectoryPattern = str_ends_with($pattern, '/');

        // Handle leading slash (match from root)
        $matchFromRoot = str_starts_with($pattern, '/');
        if ($matchFromRoot) {
            $pattern = ltrim($pattern, '/');
        }

        // Convert gitignore pattern to regex
        $regex = preg_quote($pattern, '#');

        // Replace wildcards (after preg_quote escapes them)
        $regex = str_replace('\*\*', '.*', $regex);  // ** matches any number of directories
        $regex = str_replace('\*', '[^/]*', $regex);  // * matches anything except /
        $regex = str_replace('\?', '.', $regex);      // ? matches single character

        // Build final regex based on pattern type
        if ($matchFromRoot) {
            // Pattern like "/vendor" - must match from start
            $regex = '^'.$regex.'(/|$)';
        } else {
            // Pattern like "*.log" or "build" - can match anywhere
            $regex = '(^|/)'.$regex.'(/|$)';
        }

        return (bool) preg_match('#'.$regex.'#', $filePath);
    }
}
