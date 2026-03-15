# 🛡️ Laravel Paladin

**Autonomous Self-Healing for Laravel Applications**

Laravel Paladin is an intelligent Laravel package that monitors your application logs, detects errors, and automatically attempts to fix them using AI-powered code generation. It creates isolated git worktrees for each fix attempt, runs tests to verify the fixes, and creates pull requests when successful.

## Features

- 🤖 **AI-Powered Analysis**: Uses Google Gemini to analyze and categorize errors
- 🔧 **Autonomous Fixing**: Leverages OpenCode to generate and apply fixes automatically
- 🌿 **Safe Isolation**: Each fix attempt runs in a separate git worktree
- ✅ **Test Verification**: Runs your test suite to ensure fixes don't break anything
- 🔄 **Retry Logic**: Configurable retry attempts with different approaches
- 📊 **Multiple PR Providers**: GitHub, Azure DevOps, or Email notifications
- 📝 **Comprehensive Logging**: Tracks all healing attempts in your database
- ⚡ **Queued Processing**: Runs in the background without blocking your application

## Requirements

- PHP 8.2 or higher
- Laravel 11.x or 12.x
- Git installed and configured
- A git repository for your Laravel project
- Google Gemini API key
- (Optional) GitHub or Azure DevOps token for PR creation

## Installation

### 1. Install the Package

```bash
composer require kekser/laravel-paladin
```

### 2. Publish Configuration and Migrations

```bash
php artisan vendor:publish --tag=paladin-config
php artisan vendor:publish --tag=paladin-migrations
```

### 3. Run Migrations

```bash
php artisan migrate
```

### 4. Configure Environment Variables

Add the following to your `.env` file:

```env
# Required: Google Gemini Configuration
GEMINI_API_KEY=your-gemini-api-key
PALADIN_AI_PROVIDER=gemini
PALADIN_AI_MODEL=gemini-2.0-flash-exp

# Log Monitoring
PALADIN_LOG_CHANNELS=stack,single
PALADIN_LOG_LEVELS=error,critical,alert,emergency

# Git Configuration
PALADIN_WORKTREE_PATH=../paladin-worktrees/
PALADIN_BASE_BRANCH=main

# Pull Request Provider (github, azure, or mail)
PALADIN_PR_DRIVER=github

# GitHub Configuration (if using GitHub)
PALADIN_GITHUB_TOKEN=your-github-token
PALADIN_GITHUB_OWNER=your-username-or-org
PALADIN_GITHUB_REPO=your-repo-name

# Azure DevOps Configuration (if using Azure)
PALADIN_AZURE_TOKEN=your-azure-token
PALADIN_AZURE_ORGANIZATION=your-org
PALADIN_AZURE_PROJECT=your-project
PALADIN_AZURE_REPOSITORY=your-repo

# Email Configuration (if using Mail)
PALADIN_MAIL_TO=admin@example.com
PALADIN_MAIL_SUBJECT="Laravel Paladin Fix Notification"

# Healing Configuration
PALADIN_MAX_FIX_ATTEMPTS=3
PALADIN_OPENCODE_TIMEOUT=300
PALADIN_TEST_TIMEOUT=300
```

### 5. Configure Queue (Recommended)

Laravel Paladin runs as a queued job by default. Make sure you have a queue worker running:

```bash
php artisan queue:work
```

Alternatively, configure your queue in `config/queue.php` to use database, Redis, or another driver.

## Usage

### Basic Usage

Run the self-healing process:

```bash
php artisan paladin:heal
```

This will:
1. Check for OpenCode and install it if needed
2. Scan your application logs for errors
3. Use AI to analyze and categorize issues
4. Create a git worktree for each fix attempt
5. Generate fixes using OpenCode
6. Run your test suite to verify the fix
7. Create a pull request (or send email notification) if successful

### Synchronous Mode

To run the healing process synchronously (useful for debugging):

```bash
php artisan paladin:heal --sync
```

### Custom Max Attempts

Override the maximum number of fix attempts:

```bash
php artisan paladin:heal --max-attempts=5
```

### Scheduled Healing

Add to your `app/Console/Kernel.php` to run automatically:

```php
protected function schedule(Schedule $schedule)
{
    // Run healing every hour
    $schedule->command('paladin:heal')->hourly();
    
    // Or run healing daily at 2 AM
    $schedule->command('paladin:heal')->dailyAt('02:00');
}
```

## Configuration

The configuration file `config/paladin.php` provides extensive customization options:

### AI Configuration

```php
'ai' => [
    'provider' => env('PALADIN_AI_PROVIDER', 'gemini'),
    'model' => env('PALADIN_AI_MODEL', 'gemini-2.0-flash-exp'),
    'gemini_api_key' => env('GEMINI_API_KEY'),
    'temperature' => 0.7,
    'max_tokens' => 4096,
],
```

### Log Monitoring

```php
'log_channels' => explode(',', env('PALADIN_LOG_CHANNELS', 'stack')),
'log_levels' => explode(',', env('PALADIN_LOG_LEVELS', 'error,critical,alert,emergency')),
'max_log_entries' => 100,
```

### Git Configuration

```php
'worktree_path' => env('PALADIN_WORKTREE_PATH', '../paladin-worktrees/'),
'base_branch' => env('PALADIN_BASE_BRANCH', 'main'),
'branch_prefix' => 'paladin-fix/',
```

### Pull Request Configuration

```php
'pull_request' => [
    'driver' => env('PALADIN_PR_DRIVER', 'github'),
    
    'github' => [
        'token' => env('PALADIN_GITHUB_TOKEN'),
        'owner' => env('PALADIN_GITHUB_OWNER'),
        'repo' => env('PALADIN_GITHUB_REPO'),
        'base_branch' => env('PALADIN_BASE_BRANCH', 'main'),
    ],
    
    // ... Azure DevOps and Mail configurations
],
```

## How It Works

1. **Log Scanning**: Paladin scans your configured log channels for errors
2. **AI Analysis**: Google Gemini analyzes each error and categorizes it by type and severity
3. **Prioritization**: Issues are prioritized (critical → high → medium → low)
4. **Worktree Creation**: A git worktree is created for isolated fix attempts
5. **AI Prompt Generation**: Gemini generates a detailed prompt for OpenCode
6. **Code Fixing**: OpenCode applies the fix in the worktree
7. **Testing**: Your test suite runs to verify the fix
8. **Pull Request**: If tests pass, a PR is created (or email sent)
9. **Cleanup**: The worktree is removed after completion
10. **Retry**: If the fix fails, Paladin retries with a different approach (up to max attempts)

## Database Tracking

All healing attempts are tracked in the `healing_attempts` table:

```php
use Kekser\LaravelPaladin\Models\HealingAttempt;

// Get all healing attempts
$attempts = HealingAttempt::all();

// Get only successful fixes
$fixes = HealingAttempt::fixed()->get();

// Get failed attempts
$failed = HealingAttempt::failed()->get();

// Get attempts for a specific issue type
$attempts = HealingAttempt::where('issue_type', 'exception')->get();
```

## Security Considerations

- **Git Credentials**: Ensure your git credentials are properly configured for pushing to remote
- **API Keys**: Store all API keys in `.env` and never commit them to version control
- **Access Tokens**: Use tokens with minimal required permissions (repo access for GitHub, Code Write for Azure)
- **Test Coverage**: Maintain good test coverage to catch issues before fixes are merged
- **Review PRs**: Always review auto-generated PRs before merging

## Troubleshooting

### OpenCode Not Found

Paladin will automatically install OpenCode if it's not found. If installation fails, you can manually install it:

```bash
curl -fsSL https://opencode.ai/install | sh
```

### Git Worktree Issues

If worktrees aren't being cleaned up properly:

```bash
# List all worktrees
git worktree list

# Remove a specific worktree
git worktree remove ../paladin-worktrees/paladin-fix-1234567890

# Prune all worktrees
git worktree prune
```

### Gemini API Errors

- Verify your `GEMINI_API_KEY` is correct
- Check if you've exceeded API rate limits
- Ensure the model name is correct (`gemini-2.0-flash-exp` or similar)

### Tests Not Running

- Ensure PHPUnit is installed: `composer require --dev phpunit/phpunit`
- Verify your test suite runs manually: `php artisan test`
- Check the `PALADIN_TEST_TIMEOUT` if tests are timing out

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

## License

The MIT License (MIT). Please see [LICENSE](LICENSE) file for more information.

## Credits

- Built with [Laravel AI SDK](https://github.com/laravel/ai)
- Powered by [Google Gemini](https://deepmind.google/technologies/gemini/)
- Code fixing by [OpenCode](https://opencode.ai)
- Inspired by [Spatie's package skeleton](https://github.com/spatie/package-skeleton-laravel)

## Support

If you discover any security vulnerabilities, please email security@example.com instead of using the issue tracker.

For general support and questions, please use the [GitHub Issues](https://github.com/kekser/laravel-paladin/issues) page.
