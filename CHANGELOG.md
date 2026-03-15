# Changelog

All notable changes to `laravel-paladin` will be documented in this file.

## [Unreleased]

### Added
- Initial release of Laravel Paladin
- AI-powered error analysis using Google Gemini
- Autonomous code fixing with OpenCode integration
- Git worktree isolation for safe fix attempts
- Automated test verification
- Multiple PR provider support (GitHub, Azure DevOps, Email)
- Comprehensive configuration options
- Database tracking of healing attempts
- Queued background processing
- `paladin:heal` Artisan command
- Retry logic with configurable max attempts
- Log scanning with level filtering
- Issue prioritization by severity
- Email notification templates
- Comprehensive README documentation

### Features

#### Core Functionality
- **LogScanner**: Scans Laravel logs for errors with deduplication
- **WorktreeManager**: Manages git worktrees for isolated fixes
- **OpenCodeInstaller**: Auto-installs OpenCode if not present
- **OpenCodeRunner**: Executes OpenCode with timeout support
- **TestRunner**: Runs PHPUnit tests to verify fixes
- **PullRequestManager**: Factory pattern for PR drivers

#### AI Agents
- **IssueAnalyzer**: Uses Gemini to categorize and analyze errors
- **PromptGenerator**: Generates effective prompts for OpenCode

#### PR Drivers
- **GitHubPRDriver**: Creates pull requests via GitHub API
- **AzureDevOpsPRDriver**: Creates pull requests via Azure DevOps API
- **MailNotificationDriver**: Sends email notifications as fallback

#### Models
- **HealingAttempt**: Eloquent model for tracking healing attempts

#### Commands
- **HealCommand**: Main entry point with sync/async modes

### Configuration
- Comprehensive `config/paladin.php` with all options
- Environment variable support for all settings
- Configurable log channels and levels
- Customizable worktree locations
- Flexible PR provider selection
- Adjustable timeouts and retry attempts

### Database
- Migration for `healing_attempts` table
- Tracks status, attempts, test results, and PR URLs
- Helper scopes for querying attempts

### Documentation
- Detailed README with installation instructions
- Usage examples and configuration guide
- Troubleshooting section
- Security considerations

## [1.0.0] - TBD

Initial stable release.

### Notes
- Requires PHP 8.1+
- Compatible with Laravel 10.x and 11.x
- Requires git to be installed
- Project must be a git repository
- Gemini API key required
