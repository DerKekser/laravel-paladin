<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Laravel Paladin Enabled
    |--------------------------------------------------------------------------
    |
    | This option controls whether the Paladin self-healing system is enabled.
    | When disabled, the paladin:heal command will exit without processing.
    |
    */

    'enabled' => env('PALADIN_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Log Monitoring Configuration
    |--------------------------------------------------------------------------
    |
    | Configure which log channels and levels Paladin should monitor for issues.
    | By default, it monitors standard Laravel log channels for errors and above.
    |
    */

    'log' => [
        'channels' => env('PALADIN_LOG_CHANNELS', 'stack,single'),
        'levels' => ['error', 'critical', 'alert', 'emergency'],
        'storage_path' => storage_path('logs'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Issue Detection & Analysis
    |--------------------------------------------------------------------------
    |
    | Configure how Paladin categorizes and prioritizes issues from logs.
    | Severity mapping determines the priority order for fixing issues.
    |
    */

    'issues' => [
        'max_per_run' => env('PALADIN_MAX_ISSUES_PER_RUN', 5),
        'severity_mapping' => [
            'emergency' => 'critical',
            'alert' => 'critical',
            'critical' => 'critical',
            'error' => 'high',
        ],
        'ignore_patterns' => [
            // Add regex patterns to ignore specific log entries
            // Example: '/Package .* is abandoned/',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | File Boundary Configuration
    |--------------------------------------------------------------------------
    |
    | Configure which files/directories are considered part of the project
    | and therefore fixable by Paladin. Issues originating exclusively from
    | external paths (vendor, node_modules, etc.) will be logged but skipped.
    |
    | The system respects .gitignore patterns automatically, plus additional
    | excluded paths configured here.
    |
    | PRECEDENCE ORDER:
    | 1. allowed_paths - If a file matches these paths, it's ALWAYS allowed
    |    (exception/override list that bypasses all exclusions)
    | 2. excluded_paths - Files matching these paths are excluded
    | 3. .gitignore patterns - Files matching .gitignore are excluded
    |
    | Use allowed_paths to create exceptions for normally-excluded paths.
    | For example, if you have custom code in 'app/vendor/' that you want
    | to fix even though 'vendor/' is excluded, add 'app/vendor/' to
    | allowed_paths.
    |
    | Example scenario:
    |   excluded_paths: ['vendor/']
    |   allowed_paths: ['app/vendor/']
    |
    |   Result:
    |   - vendor/laravel/framework/File.php → excluded
    |   - app/vendor/CustomLib.php → allowed (exception)
    |   - app/Http/Controllers/TestController.php → allowed (not excluded)
    |
    */

    'file_boundaries' => [
        // Paths that are ALWAYS excluded (in addition to .gitignore patterns)
        // Errors originating only from these paths will be skipped
        // NOTE: These can be overridden by allowed_paths
        'excluded_paths' => [
            'vendor/',
            'node_modules/',
        ],

        // Exception list - paths that should be allowed even if they match
        // excluded_paths or .gitignore patterns. Use this to create exceptions
        // for specific directories that would normally be excluded.
        // Leave empty [] if you don't need exceptions (recommended)
        // Example: ['app/vendor/', 'custom-packages/']
        'allowed_paths' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Worktree Configuration
    |--------------------------------------------------------------------------
    |
    | Git worktrees are used to isolate fix attempts. Configure where they
    | are created and how they are managed.
    |
    */

    'worktree' => [
        'base_path' => env('PALADIN_WORKTREE_PATH', '../paladin-worktrees'),
        'naming_pattern' => 'paladin-fix-{issue_id}-{timestamp}',
        'cleanup_after_success' => true,
        'cleanup_after_days' => 7,

        /*
        |----------------------------------------------------------------------
        | Worktree Setup Configuration
        |----------------------------------------------------------------------
        |
        | Configure how Paladin sets up worktrees before running fixes.
        | This ensures the worktree has all dependencies and configuration
        | needed for tests to run successfully.
        |
        */

        'setup' => [
            // Enable/disable worktree setup
            'enabled' => env('PALADIN_WORKTREE_SETUP_ENABLED', true),

            // Run composer install in the worktree
            'composer_install' => true,

            // Flags to pass to composer install
            // --no-dev: Skip development dependencies (faster, smaller)
            // --prefer-dist: Use distribution packages (faster)
            // --no-interaction: Don't ask any interactive questions
            'composer_flags' => '--no-interaction --prefer-dist --no-dev',

            // Copy environment file to worktree
            'copy_env' => true,

            // Which env file to copy (falls back to .env if not found)
            'env_source' => '.env.testing',

            // Generate APP_KEY if missing in the copied .env file
            'generate_key' => true,

            // Custom commands to run after setup (e.g., cache clearing, migrations)
            // Commands are executed in the worktree directory
            'custom_commands' => [
                // Example: 'php artisan config:clear',
                // Example: 'php artisan cache:clear',
                // Example: 'php artisan migrate --force',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | OpenCode Integration
    |--------------------------------------------------------------------------
    |
    | Configure how Paladin integrates with OpenCode for automated fixes.
    | Auto-install will attempt to install OpenCode if not found.
    |
    */

    'opencode' => [
        'binary_path' => env('PALADIN_OPENCODE_PATH', 'opencode'),
        'auto_install' => env('PALADIN_OPENCODE_AUTO_INSTALL', true),
        'timeout' => 600, // 10 minutes
    ],

    /*
    |--------------------------------------------------------------------------
    | Testing & Verification
    |--------------------------------------------------------------------------
    |
    | Configure how Paladin verifies that fixes work correctly.
    | Tests must pass for a fix to be considered successful.
    |
    */

    'testing' => [
        'command' => env('PALADIN_TEST_COMMAND', 'php artisan test'),
        'timeout' => 300, // 5 minutes
        'max_fix_attempts' => env('PALADIN_MAX_FIX_ATTEMPTS', 3),
        'require_passing_tests' => true,
        'skip_tests' => env('PALADIN_SKIP_TESTS', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Git Configuration
    |--------------------------------------------------------------------------
    |
    | Configure git branch naming, commit messages, and PR templates.
    |
    */

    'git' => [
        'default_branch' => env('PALADIN_DEFAULT_BRANCH', 'main'),
        'branch_prefix' => 'paladin/fix',
        'commit_message_template' => '[Paladin] Fix: {issue_title}

{issue_description}

Severity: {severity}
Attempt: {attempt_number}/{max_attempts}
Auto-generated by Laravel Paladin',
        'pr_title_template' => '[Paladin] Fix: {issue_title}',
        'pr_body_template' => '## 🛡️ Automated Fix by Laravel Paladin

### Issue Details
- **Type**: {issue_type}
- **Severity**: {severity}
- **Affected Files**: {affected_files}

### Description
{issue_description}

### Fix Attempt
- **Attempt**: {attempt_number} of {max_attempts}
- **Tests Status**: ✅ Passing

### Stack Trace
```
{stack_trace}
```

---
*This PR was automatically generated by [Laravel Paladin](https://github.com/DerKekser/laravel-paladin)*',
    ],

    /*
    |--------------------------------------------------------------------------
    | Pull Request Providers
    |--------------------------------------------------------------------------
    |
    | Configure the default provider and credentials for creating pull requests.
    | Supported: 'github', 'azure-devops', 'mail'
    |
    */

    'pr_provider' => env('PALADIN_PR_PROVIDER', 'github'),

    'providers' => [
        'github' => [
            'token' => env('PALADIN_GITHUB_TOKEN'),
            'api_url' => env('PALADIN_GITHUB_API_URL', 'https://api.github.com'),
        ],

        'azure-devops' => [
            'organization' => env('PALADIN_AZURE_DEVOPS_ORG'),
            'project' => env('PALADIN_AZURE_DEVOPS_PROJECT'),
            'token' => env('PALADIN_AZURE_DEVOPS_PAT'),
            'api_url' => env('PALADIN_AZURE_DEVOPS_URL', 'https://dev.azure.com'),
        ],

        'mail' => [
            'to' => env('PALADIN_MAIL_TO'),
            'from' => env('PALADIN_MAIL_FROM', env('MAIL_FROM_ADDRESS')),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Laravel AI Configuration
    |--------------------------------------------------------------------------
    |
    | Configure the AI provider and model used for issue analysis and prompt generation.
    |
    | Supported Providers & Required Environment Variables:
    |
    | Provider    | Required Env Variables
    | ------------|-------------------------------------------------------
    | anthropic   | ANTHROPIC_API_KEY
    | azure       | AZURE_OPENAI_API_KEY, AZURE_OPENAI_ENDPOINT
    | cohere      | COHERE_API_KEY
    | deepseek    | DEEPSEEK_API_KEY
    | gemini      | GEMINI_API_KEY
    | groq        | GROQ_API_KEY
    | mistral     | MISTRAL_API_KEY
    | ollama      | (none - optional: OLLAMA_BASE_URL, defaults to localhost:11434)
    | openai      | OPENAI_API_KEY
    | openrouter  | OPENROUTER_API_KEY
    | xai         | XAI_API_KEY
    |
    | Example Configurations:
    |
    | # OpenAI GPT-4
    | PALADIN_AI_PROVIDER=openai
    | PALADIN_AI_MODEL=gpt-4o
    | OPENAI_API_KEY=sk-proj-...
    |
    | # Anthropic Claude
    | PALADIN_AI_PROVIDER=anthropic
    | PALADIN_AI_MODEL=claude-3-5-sonnet-20241022
    | ANTHROPIC_API_KEY=sk-ant-...
    |
    | # Google Gemini (default)
    | PALADIN_AI_PROVIDER=gemini
    | PALADIN_AI_MODEL=gemini-2.0-flash-exp
    | GEMINI_API_KEY=AIza...
    |
    | # Local Ollama
    | PALADIN_AI_PROVIDER=ollama
    | PALADIN_AI_MODEL=llama3.2
    | OLLAMA_BASE_URL=http://localhost:11434  # optional
    |
    | # Azure OpenAI
    | PALADIN_AI_PROVIDER=azure
    | PALADIN_AI_MODEL=gpt-4
    | AZURE_OPENAI_API_KEY=abc123...
    | AZURE_OPENAI_ENDPOINT=https://myservice.openai.azure.com/
    |
    */

    'ai' => [
        'provider' => env('PALADIN_AI_PROVIDER', 'gemini'),
        'model' => env('PALADIN_AI_MODEL', 'gemini-2.0-flash-exp'),
        'temperature' => env('PALADIN_AI_TEMPERATURE', 0.7),

        // API Credentials (stored in config for proper cache support)
        'credentials' => [
            'anthropic_api_key' => env('ANTHROPIC_API_KEY'),
            'azure_openai_api_key' => env('AZURE_OPENAI_API_KEY'),
            'azure_openai_endpoint' => env('AZURE_OPENAI_ENDPOINT'),
            'cohere_api_key' => env('COHERE_API_KEY'),
            'deepseek_api_key' => env('DEEPSEEK_API_KEY'),
            'gemini_api_key' => env('GEMINI_API_KEY'),
            'groq_api_key' => env('GROQ_API_KEY'),
            'mistral_api_key' => env('MISTRAL_API_KEY'),
            'ollama_base_url' => env('OLLAMA_BASE_URL'),
            'openai_api_key' => env('OPENAI_API_KEY'),
            'openrouter_api_key' => env('OPENROUTER_API_KEY'),
            'xai_api_key' => env('XAI_API_KEY'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue Configuration
    |--------------------------------------------------------------------------
    |
    | Configure whether healing should run in the background queue.
    |
    */

    'queue' => [
        'enabled' => env('PALADIN_QUEUE_ENABLED', true),
        'connection' => env('PALADIN_QUEUE_CONNECTION'),
        'queue' => env('PALADIN_QUEUE_NAME', 'default'),
    ],

];
