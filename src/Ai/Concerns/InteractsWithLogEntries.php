<?php

namespace Kekser\LaravelPaladin\Ai\Concerns;

trait InteractsWithLogEntries
{
    /**
     * Format log entries for a prompt.
     */
    protected function formatLogEntries(array $logEntries): string
    {
        $formattedEntries = array_map(function ($entry) {
            return sprintf(
                "[%s] %s: %s\n%s",
                date('Y-m-d H:i:s', $entry['timestamp'] ?? time()),
                strtoupper($entry['level'] ?? 'ERROR'),
                $entry['message'] ?? 'No message',
                $entry['stack_trace'] ?? ''
            );
        }, $logEntries);

        return implode("\n\n---\n\n", $formattedEntries);
    }
}
