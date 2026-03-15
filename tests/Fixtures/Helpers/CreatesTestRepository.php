<?php

namespace Kekser\LaravelPaladin\Tests\Fixtures\Helpers;

trait CreatesTestRepository
{
    protected string $testRepoPath;

    /**
     * Create a temporary git repository for testing.
     */
    protected function createTestRepository(array $options = []): string
    {
        $this->testRepoPath = $this->createTempDirectory('git-test-');

        // Initialize git repository
        $this->runGitCommand('init', $this->testRepoPath);
        $this->runGitCommand('config user.email "test@example.com"', $this->testRepoPath);
        $this->runGitCommand('config user.name "Test User"', $this->testRepoPath);

        // Create initial commit
        if ($options['initial_commit'] ?? true) {
            // Determine target branch name
            $targetBranch = $options['branch'] ?? null;
            if ($targetBranch && is_string($targetBranch)) {
                // Rename the default branch to the target branch before creating commit
                $this->runGitCommand(sprintf('checkout -b %s', escapeshellarg($targetBranch)), $this->testRepoPath);
            }

            file_put_contents($this->testRepoPath.'/README.md', '# Test Repository');
            $this->runGitCommand('add .', $this->testRepoPath);
            $this->runGitCommand('commit -m "Initial commit"', $this->testRepoPath);
        } elseif ($options['branch'] ?? false) {
            // Create branch without initial commit
            $branch = is_string($options['branch']) ? $options['branch'] : 'main';
            $this->runGitCommand(sprintf('checkout -b %s', escapeshellarg($branch)), $this->testRepoPath);
        }

        // Add remote if specified
        if ($options['remote'] ?? false) {
            $remote = is_string($options['remote'])
                ? $options['remote']
                : 'https://github.com/test-owner/test-repo.git';
            $this->runGitCommand(sprintf('remote add origin %s', escapeshellarg($remote)), $this->testRepoPath);
        }

        return $this->testRepoPath;
    }

    /**
     * Run a git command in a directory.
     */
    protected function runGitCommand(string $command, ?string $cwd = null): array
    {
        $cwd = $cwd ?? $this->testRepoPath ?? getcwd();
        $fullCommand = sprintf('git %s', $command);

        exec(sprintf('cd %s && %s 2>&1', escapeshellarg($cwd), $fullCommand), $output, $returnCode);

        return [
            'output' => $output,
            'return_code' => $returnCode,
            'success' => $returnCode === 0,
        ];
    }

    /**
     * Clean up test repository after tests.
     */
    protected function cleanupTestRepository(): void
    {
        if (isset($this->testRepoPath) && is_dir($this->testRepoPath)) {
            $this->deleteDirectory($this->testRepoPath);
        }
    }

    /**
     * Create a file in the test repository.
     */
    protected function createFileInRepo(string $filename, string $content = ''): string
    {
        $path = $this->testRepoPath.'/'.$filename;
        $dir = dirname($path);

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($path, $content);

        return $path;
    }

    /**
     * Add and commit a file in the test repository.
     */
    protected function commitFile(string $filename, string $content = '', string $message = 'Add file'): void
    {
        $this->createFileInRepo($filename, $content);
        $this->runGitCommand(sprintf('add %s', escapeshellarg($filename)));
        $this->runGitCommand(sprintf('commit -m %s', escapeshellarg($message)));
    }

    /**
     * Create a new branch in the test repository.
     */
    protected function createBranch(string $branchName, bool $checkout = true): void
    {
        $command = $checkout
            ? sprintf('checkout -b %s', escapeshellarg($branchName))
            : sprintf('branch %s', escapeshellarg($branchName));
        $this->runGitCommand($command);
    }

    /**
     * Get the current branch of the test repository.
     */
    protected function getCurrentBranch(): string
    {
        $result = $this->runGitCommand('rev-parse --abbrev-ref HEAD');

        return trim($result['output'][0] ?? '');
    }

    /**
     * Get the list of worktrees.
     */
    protected function getWorktrees(): array
    {
        $result = $this->runGitCommand('worktree list --porcelain');

        if (! $result['success']) {
            return [];
        }

        $worktrees = [];
        $current = null;

        foreach ($result['output'] as $line) {
            if (str_starts_with($line, 'worktree ')) {
                if ($current) {
                    $worktrees[] = $current;
                }
                $current = ['path' => substr($line, 9)];
            } elseif (str_starts_with($line, 'branch ')) {
                if ($current) {
                    $current['branch'] = substr($line, 7);
                }
            }
        }

        if ($current) {
            $worktrees[] = $current;
        }

        return $worktrees;
    }
}
