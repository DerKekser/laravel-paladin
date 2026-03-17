<?php

namespace Kekser\LaravelPaladin\Drivers\Mail;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Kekser\LaravelPaladin\Contracts\PullRequestDriver;

class MailNotificationDriver implements PullRequestDriver
{
    protected ?string $to;

    protected ?string $from;

    public function __construct()
    {
        $this->to = config('paladin.providers.mail.to');
        $this->from = config('paladin.providers.mail.from');
    }

    /**
     * Send an email notification about the fix instead of creating a PR.
     */
    public function createPullRequest(
        string $branch,
        string $title,
        string $body,
        string $baseBranch = 'main'
    ): ?string {
        if (! $this->isConfigured()) {
            Log::warning('[Paladin] Mail driver is not configured, skipping notification');

            return null;
        }

        try {
            Log::info('[Paladin] Sending fix notification email', [
                'to' => $this->to,
                'branch' => $branch,
            ]);

            Mail::send(
                'paladin::fix-notification',
                [
                    'title' => $title,
                    'branch' => $branch,
                    'baseBranch' => $baseBranch,
                    'body' => $body,
                    'repository' => $this->getRepository(),
                ],
                function ($message) use ($title) {
                    $message->to($this->to)
                        ->from($this->from)
                        ->subject("[Paladin] {$title}");
                }
            );

            Log::info('[Paladin] Fix notification email sent successfully');

            return 'email:'.$this->to;
        } catch (\Exception $e) {
            Log::error('[Paladin] Failed to send fix notification email', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Check if the driver is properly configured.
     */
    public function isConfigured(): bool
    {
        return ! empty($this->to) && ! empty($this->from);
    }

    /**
     * Get the repository name from git.
     */
    protected function getRepository(): string
    {
        exec('git remote get-url origin 2>&1', $output, $returnCode);

        if ($returnCode === 0 && ! empty($output)) {
            return $output[0];
        }

        return 'Unknown repository';
    }
}
