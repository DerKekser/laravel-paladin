<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Laravel Paladin - Fix Notification</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
            background-color: #f5f5f5;
        }
        .container {
            background: white;
            border-radius: 8px;
            padding: 30px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .header {
            border-bottom: 3px solid #3490dc;
            padding-bottom: 20px;
            margin-bottom: 30px;
        }
        .header h1 {
            margin: 0;
            color: #2d3748;
            font-size: 24px;
        }
        .header .subtitle {
            color: #718096;
            font-size: 14px;
            margin-top: 5px;
        }
        .status-badge {
            display: inline-block;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-top: 10px;
        }
        .status-success {
            background-color: #48bb78;
            color: white;
        }
        .status-failure {
            background-color: #f56565;
            color: white;
        }
        .section {
            margin-bottom: 30px;
        }
        .section h2 {
            color: #2d3748;
            font-size: 18px;
            margin-bottom: 15px;
            border-bottom: 1px solid #e2e8f0;
            padding-bottom: 8px;
        }
        .info-grid {
            display: grid;
            grid-template-columns: 140px 1fr;
            gap: 12px;
            margin-bottom: 20px;
        }
        .info-label {
            font-weight: 600;
            color: #4a5568;
        }
        .info-value {
            color: #2d3748;
        }
        .code-block {
            background-color: #f7fafc;
            border-left: 4px solid #3490dc;
            padding: 15px;
            margin: 15px 0;
            border-radius: 4px;
            overflow-x: auto;
        }
        .code-block pre {
            margin: 0;
            font-family: 'Monaco', 'Menlo', 'Ubuntu Mono', monospace;
            font-size: 13px;
            line-height: 1.5;
            color: #2d3748;
        }
        .log-entry {
            background-color: #fff5f5;
            border-left: 4px solid #f56565;
            padding: 15px;
            margin: 15px 0;
            border-radius: 4px;
        }
        .log-entry .log-level {
            font-weight: 700;
            color: #c53030;
            text-transform: uppercase;
            font-size: 12px;
            margin-bottom: 5px;
        }
        .log-entry .log-message {
            font-family: 'Monaco', 'Menlo', 'Ubuntu Mono', monospace;
            font-size: 13px;
            color: #2d3748;
            margin: 8px 0;
        }
        .footer {
            margin-top: 40px;
            padding-top: 20px;
            border-top: 1px solid #e2e8f0;
            text-align: center;
            color: #718096;
            font-size: 12px;
        }
        .button {
            display: inline-block;
            background-color: #3490dc;
            color: white;
            padding: 12px 24px;
            text-decoration: none;
            border-radius: 6px;
            font-weight: 600;
            margin-top: 10px;
        }
        .button:hover {
            background-color: #2779bd;
        }
        .test-results {
            background-color: #f7fafc;
            padding: 15px;
            border-radius: 4px;
            margin: 10px 0;
        }
        .test-success {
            color: #38a169;
        }
        .test-failure {
            color: #e53e3e;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🛡️ Laravel Paladin - Fix Notification</h1>
            <div class="subtitle">Autonomous Self-Healing Report</div>
            <span class="status-badge {{ $attempt->status === 'fixed' ? 'status-success' : 'status-failure' }}">
                {{ $attempt->status }}
            </span>
        </div>

        <div class="section">
            <h2>Summary</h2>
            <div class="info-grid">
                <div class="info-label">Issue Type:</div>
                <div class="info-value">{{ $attempt->issue_type }}</div>

                <div class="info-label">Severity:</div>
                <div class="info-value">{{ ucfirst($attempt->severity) }}</div>

                <div class="info-label">Attempt:</div>
                <div class="info-value">{{ $attempt->attempt_number }} of {{ $maxAttempts }}</div>

                <div class="info-label">Branch:</div>
                <div class="info-value"><code>{{ $attempt->branch_name }}</code></div>

                <div class="info-label">Started At:</div>
                <div class="info-value">{{ $attempt->created_at->format('Y-m-d H:i:s') }}</div>

                @if($attempt->completed_at)
                <div class="info-label">Completed At:</div>
                <div class="info-value">{{ $attempt->completed_at->format('Y-m-d H:i:s') }}</div>
                @endif
            </div>
        </div>

        <div class="section">
            <h2>Issue Description</h2>
            <p>{{ $attempt->issue_description }}</p>
        </div>

        <div class="section">
            <h2>Log Entry</h2>
            <div class="log-entry">
                <div class="log-level">{{ $logEntry['level'] ?? 'ERROR' }}</div>
                <div class="log-message">{{ $logEntry['message'] ?? 'No message available' }}</div>
                @if(isset($logEntry['context']) && !empty($logEntry['context']))
                <details style="margin-top: 10px;">
                    <summary style="cursor: pointer; color: #4a5568; font-weight: 600;">Context</summary>
                    <pre style="margin-top: 10px; font-size: 12px;">{{ json_encode($logEntry['context'], JSON_PRETTY_PRINT) }}</pre>
                </details>
                @endif
            </div>
        </div>

        @if($attempt->ai_prompt)
        <div class="section">
            <h2>AI Prompt Used</h2>
            <div class="code-block">
                <pre>{{ $attempt->ai_prompt }}</pre>
            </div>
        </div>
        @endif

        @if($attempt->test_output)
        <div class="section">
            <h2>Test Results</h2>
            <div class="test-results">
                <div class="{{ $attempt->tests_passed ? 'test-success' : 'test-failure' }}">
                    <strong>{{ $attempt->tests_passed ? '✓ Tests Passed' : '✗ Tests Failed' }}</strong>
                </div>
                <details style="margin-top: 10px;">
                    <summary style="cursor: pointer; color: #4a5568; font-weight: 600;">View Full Test Output</summary>
                    <pre style="margin-top: 10px; font-size: 12px; overflow-x: auto;">{{ $attempt->test_output }}</pre>
                </details>
            </div>
        </div>
        @endif

        @if($attempt->error_message)
        <div class="section">
            <h2>Error Details</h2>
            <div class="log-entry">
                <div class="log-message">{{ $attempt->error_message }}</div>
            </div>
        </div>
        @endif

        <div class="section">
            <h2>Next Steps</h2>
            @if($attempt->status === 'fixed')
                <p>
                    <strong>Great news!</strong> The issue has been successfully fixed and all tests are passing.
                </p>
                @if($attempt->pull_request_url)
                    <p>A pull request has been created with the fix:</p>
                    <a href="{{ $attempt->pull_request_url }}" class="button">View Pull Request</a>
                @else
                    <p>Please check the branch <code>{{ $attempt->branch_name }}</code> in your repository and create a pull request manually if needed.</p>
                @endif
            @else
                <p>
                    The fix attempt was unsuccessful. 
                    @if($attempt->attempt_number < $maxAttempts)
                        Laravel Paladin will automatically retry with a different approach.
                    @else
                        The maximum number of attempts has been reached. Manual intervention may be required.
                    @endif
                </p>
                <p>
                    You can review the attempted fix in the branch <code>{{ $attempt->branch_name }}</code>.
                </p>
            @endif
        </div>

        <div class="footer">
            <p>
                This notification was automatically generated by Laravel Paladin<br>
                Autonomous Self-Healing for Laravel Applications
            </p>
        </div>
    </div>
</body>
</html>
