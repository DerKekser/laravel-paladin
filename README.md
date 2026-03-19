# 🛡️ Laravel Paladin

**Autonomous Self-Healing for Laravel Applications**

Laravel Paladin is an intelligent Laravel package that monitors your application logs, detects errors, and automatically attempts to fix them using AI-powered code generation. It creates isolated git worktrees for each fix attempt, runs tests to verify the fixes, and creates pull requests when successful.

## Inspiration

This package was inspired by Taylor Otwell's demonstration of a self-healing Laravel application at Laracon 2025 in Amsterdam. After seeing the potential of AI-powered autonomous error fixing, I set out to build a practical implementation that any Laravel developer could use in their applications.

## Features

- 🤖 **AI-Powered Analysis**: Supports multiple AI providers including OpenAI, Anthropic Claude, Google Gemini, and more
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
- AI provider API key (see supported providers below)
- (Optional) GitHub or Azure DevOps token for PR creation

## Installation

### 1. Install the Package

```bash
composer require derkekser/laravel-paladin
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
# Enable/Disable Paladin
PALADIN_ENABLED=true

# AI Provider Configuration
# Supported evaluators: laravel-ai, opencode
PALADIN_AI_EVALUATOR=laravel-ai

# AI Provider Configuration (for laravel-ai evaluator)
# Supported providers: anthropic, azure, cohere, deepseek, gemini, groq, mistral, ollama, openai, openrouter, xai
PALADIN_AI_PROVIDER=gemini
PALADIN_AI_MODEL=gemini-2.0-flash-exp

# Provider-specific API keys (choose based on your provider)
GEMINI_API_KEY=your-gemini-api-key
# OPENAI_API_KEY=your-openai-api-key
# ANTHROPIC_API_KEY=your-anthropic-api-key
# (see config/paladin.php for all provider options)

# Log Monitoring
PALADIN_LOG_CHANNELS=stack,single

# Git Configuration
PALADIN_WORKTREE_PATH=../paladin-worktrees
PALADIN_DEFAULT_BRANCH=main

# Pull Request Provider (github, azure-devops, or mail)
PALADIN_PR_PROVIDER=github

# GitHub Configuration (if using GitHub)
PALADIN_GITHUB_TOKEN=your-github-token

# Azure DevOps Configuration (if using Azure)
PALADIN_AZURE_DEVOPS_PAT=your-azure-token
PALADIN_AZURE_DEVOPS_ORG=your-org
PALADIN_AZURE_DEVOPS_PROJECT=your-project

# Email Configuration (if using Mail)
PALADIN_MAIL_TO=admin@example.com
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

### Check Healing Status

View the status of recent healing attempts:

```bash
# Show overall statistics and recent attempts
php artisan paladin:status

# Filter by status
php artisan paladin:status --status=fixed
php artisan paladin:status --status=failed
php artisan paladin:status --status=in_progress

# Show more attempts
php artisan paladin:status --limit=20

# Show detailed information including stack traces
php artisan paladin:status --verbose
```

### Cleanup Old Worktrees

Clean up old worktrees to free disk space:

```bash
# Interactive cleanup of worktrees older than configured days
php artisan paladin:cleanup

# Skip confirmation
php artisan paladin:cleanup --force

# Override cleanup threshold
php artisan paladin:cleanup --days=14

# Dry run to see what would be deleted
php artisan paladin:cleanup --dry-run

# Delete all worktrees (use with caution)
php artisan paladin:cleanup --all
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

### AI Evaluator Configuration

Laravel Paladin supports different AI evaluators and providers:

**Evaluators:**
- `laravel-ai` (default) - Uses the [Laravel AI SDK](https://github.com/laravel/ai) with multiple supported backends
- `opencode` - Uses the OpenCode CLI directly for analysis (no API keys required)

**Supported Providers (for `laravel-ai` evaluator):**
- `anthropic` - Claude models (requires `ANTHROPIC_API_KEY`)
- `azure` - Azure OpenAI (requires `AZURE_OPENAI_API_KEY`, `AZURE_OPENAI_ENDPOINT`)
- `cohere` - Cohere models (requires `COHERE_API_KEY`)
- `deepseek` - DeepSeek models (requires `DEEPSEEK_API_KEY`)
- `gemini` - Google Gemini (requires `GEMINI_API_KEY`) - **default**
- `groq` - Groq models (requires `GROQ_API_KEY`)
- `mistral` - Mistral AI (requires `MISTRAL_API_KEY`)
- `ollama` - Local Ollama models (optional: `OLLAMA_BASE_URL`)
- `openai` - OpenAI GPT models (requires `OPENAI_API_KEY`)
- `openrouter` - OpenRouter (requires `OPENROUTER_API_KEY`)
- `xai` - xAI models (requires `XAI_API_KEY`)

```php
'evaluator' => env('PALADIN_AI_EVALUATOR', 'laravel-ai'),

'evaluators' => [
    'laravel-ai' => [
        'provider' => env('PALADIN_AI_PROVIDER', 'gemini'),
        'model' => env('PALADIN_AI_MODEL', 'gemini-2.0-flash-exp'),
        'temperature' => env('PALADIN_AI_TEMPERATURE', 0.7),
        // ...
    ],
],
```

**Example Configurations:**

```env
# Google Gemini (Default)
PALADIN_AI_EVALUATOR=laravel-ai
PALADIN_AI_PROVIDER=gemini
PALADIN_AI_MODEL=gemini-2.0-flash-exp
GEMINI_API_KEY=your-gemini-api-key

# OpenCode (No API keys needed)
PALADIN_AI_EVALUATOR=opencode

# OpenAI GPT-4
PALADIN_AI_EVALUATOR=laravel-ai
PALADIN_AI_PROVIDER=openai
PALADIN_AI_MODEL=gpt-4o
OPENAI_API_KEY=sk-proj-...
```

### Log Monitoring

```php
'log' => [
    'channels' => env('PALADIN_LOG_CHANNELS', 'stack,single'),
    'levels' => ['error', 'critical', 'alert', 'emergency'],
    'storage_path' => storage_path('logs'),
],
```

### Git Configuration

```php
'worktree' => [
    'base_path' => env('PALADIN_WORKTREE_PATH', '../paladin-worktrees'),
    'naming_pattern' => 'paladin-fix-{issue_id}-{timestamp}',
    'cleanup_after_success' => true,
    'cleanup_after_days' => 7,
],

'git' => [
    'default_branch' => env('PALADIN_DEFAULT_BRANCH', 'main'),
    'branch_prefix' => 'paladin/fix',
],
```

### Pull Request Configuration

```php
'pr_provider' => env('PALADIN_PR_PROVIDER', 'github'),

'providers' => [
    'github' => [
        'driver' => \Kekser\LaravelPaladin\Drivers\GitHub\GitHubPRDriver::class,
        'token' => env('PALADIN_GITHUB_TOKEN'),
        'api_url' => env('PALADIN_GITHUB_API_URL', 'https://api.github.com'),
    ],
    
    'azure-devops' => [
        'driver' => \Kekser\LaravelPaladin\Drivers\AzureDevOps\AzureDevOpsPRDriver::class,
        'organization' => env('PALADIN_AZURE_DEVOPS_ORG'),
        'project' => env('PALADIN_AZURE_DEVOPS_PROJECT'),
        'token' => env('PALADIN_AZURE_DEVOPS_PAT'),
        'api_url' => env('PALADIN_AZURE_DEVOPS_URL', 'https://dev.azure.com'),
    ],
    
    'mail' => [
        'driver' => \Kekser\LaravelPaladin\Drivers\Mail\MailNotificationDriver::class,
        'to' => env('PALADIN_MAIL_TO'),
        'from' => env('PALADIN_MAIL_FROM', env('MAIL_FROM_ADDRESS')),
    ],
],
```

## How It Works

1. **Log Scanning**: Paladin scans your configured log channels for errors
2. **AI Analysis**: Your configured AI provider analyzes each error and categorizes it by type and severity
3. **Prioritization**: Issues are prioritized (critical → high → medium → low)
4. **Worktree Creation**: A git worktree is created for isolated fix attempts
5. **AI Prompt Generation**: The AI generates a detailed prompt for OpenCode
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

### AI API Errors

- Verify your API key is correct for your chosen provider
- Check if you've exceeded API rate limits
- Ensure the model name is correct for your provider
- For specific provider issues, consult their documentation

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
- Built with [Spatie Laravel Package Tools](https://github.com/spatie/laravel-package-tools)
- Code fixing by [OpenCode](https://opencode.ai)

## Support

For general support and questions, please use the [GitHub Issues](https://github.com/DerKekser/laravel-paladin/issues) page.
