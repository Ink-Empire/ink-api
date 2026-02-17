<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\SlackService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendSlackSupportNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(
        public int $userId,
        public ?string $message = null
    ) {}

    public function handle(SlackService $slackService): void
    {
        $user = User::find($this->userId);

        if (!$user) {
            return;
        }

        $slackService->notifySupportRequest($user, $this->message);
    }
}
