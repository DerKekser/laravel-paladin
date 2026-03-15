<?php

namespace Kekser\LaravelPaladin\Commands;

use Illuminate\Console\Command;
use Kekser\LaravelPaladin\Jobs\ProcessSelfHealingJob;

class HealCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'paladin:heal
                            {--sync : Run the healing process synchronously instead of queuing}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Scan logs for errors and attempt to automatically fix them using AI';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('🛡️  Laravel Paladin - Autonomous Self-Healing');
        $this->newLine();

        $sync = $this->option('sync');

        // Validate configuration
        if (!$this->validateConfiguration()) {
            return self::FAILURE;
        }

        $this->info('Starting self-healing process...');
        $this->newLine();

        if ($sync) {
            $this->warn('Running in synchronous mode - this may take a while...');
            $this->newLine();
            
            try {
                ProcessSelfHealingJob::dispatchSync();
                
                $this->newLine();
                $this->info('✓ Self-healing process completed successfully!');
                $this->info('Check the healing_attempts table for detailed results.');
                
                return self::SUCCESS;
            } catch (\Exception $e) {
                $this->newLine();
                $this->error('✗ Self-healing process failed: ' . $e->getMessage());
                
                if ($this->output->isVerbose()) {
                    $this->error($e->getTraceAsString());
                }
                
                return self::FAILURE;
            }
        } else {
            ProcessSelfHealingJob::dispatch();
            
            $this->info('✓ Self-healing job has been queued successfully!');
            $this->info('The healing process will run in the background.');
            $this->info('Check the healing_attempts table to monitor progress.');
            $this->newLine();
            $this->comment('Tip: Use --sync flag to run synchronously and see real-time output.');
            
            return self::SUCCESS;
        }
    }

    /**
     * Validate the Paladin configuration.
     */
    protected function validateConfiguration(): bool
    {
        $errors = [];

        // Check if AI provider is configured
        if (!config('paladin.ai.provider')) {
            $errors[] = 'AI provider not configured. Set PALADIN_AI_PROVIDER in your .env file.';
        }

        // Check if AI model is configured
        if (!config('paladin.ai.model_analysis') || !config('paladin.ai.model_prompts')) {
            $errors[] = 'AI models not configured. Set PALADIN_AI_MODEL_ANALYSIS and PALADIN_AI_MODEL_PROMPTS in your .env file.';
        }

        // Check if Gemini API key is set (if using Gemini)
        if (config('paladin.ai.provider') === 'gemini' && !env('GEMINI_API_KEY')) {
            $errors[] = 'Gemini API key not configured. Set GEMINI_API_KEY in your .env file.';
        }

        // Check if git is available
        if (!$this->isGitAvailable()) {
            $errors[] = 'Git is not available on your system. Laravel Paladin requires git to be installed.';
        }

        // Check if we're in a git repository
        if (!$this->isGitRepository()) {
            $errors[] = 'Not in a git repository. Laravel Paladin requires your project to be a git repository.';
        }

        // Check if log channels are configured
        if (empty(config('paladin.log.channels'))) {
            $errors[] = 'No log channels configured. Set PALADIN_LOG_CHANNELS in your .env file.';
        }

        // Check if PR driver is configured properly
        $prDriver = config('paladin.pr_provider');
        if ($prDriver === 'github' && !config('paladin.providers.github.token')) {
            $errors[] = 'GitHub token not configured. Set PALADIN_GITHUB_TOKEN in your .env file.';
        }

        if ($prDriver === 'azure-devops' && (!config('paladin.providers.azure-devops.organization') || !config('paladin.providers.azure-devops.token'))) {
            $errors[] = 'Azure DevOps not fully configured. Set PALADIN_AZURE_DEVOPS_ORG and PALADIN_AZURE_DEVOPS_PAT in your .env file.';
        }

        if ($prDriver === 'mail' && !config('paladin.providers.mail.to')) {
            $errors[] = 'Mail recipient not configured. Set PALADIN_MAIL_TO in your .env file.';
        }

        // Display errors if any
        if (!empty($errors)) {
            $this->error('Configuration errors detected:');
            $this->newLine();
            
            foreach ($errors as $error) {
                $this->error('  • ' . $error);
            }
            
            $this->newLine();
            $this->comment('Please fix these issues before running the heal command.');
            
            return false;
        }

        return true;
    }

    /**
     * Check if git is available on the system.
     */
    protected function isGitAvailable(): bool
    {
        $output = [];
        $returnCode = 0;
        exec('git --version 2>&1', $output, $returnCode);
        
        return $returnCode === 0;
    }

    /**
     * Check if the current directory is a git repository.
     */
    protected function isGitRepository(): bool
    {
        $output = [];
        $returnCode = 0;
        exec('git rev-parse --git-dir 2>&1', $output, $returnCode);
        
        return $returnCode === 0;
    }
}
