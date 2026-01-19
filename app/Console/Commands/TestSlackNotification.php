<?php

namespace App\Console\Commands;

use App\Services\SlackService;
use Illuminate\Console\Command;

class TestSlackNotification extends Command
{
    protected $signature = 'slack:test {--message= : Custom message to send}';

    protected $description = 'Send a test notification to Slack';

    public function handle(SlackService $slackService)
    {
        $message = $this->option('message') ?? "Test notification from InkedIn\n━━━━━━━━━━━━━━━━━━\nThis is a test message to verify Slack integration is working.";

        $this->info('Sending test message to Slack...');

        if ($slackService->send($message)) {
            $this->info('Message sent successfully!');
            return Command::SUCCESS;
        }

        $this->error('Failed to send message. Check logs for details.');
        return Command::FAILURE;
    }
}
