<?php

namespace App\Jobs;

use App\Models\User;
use App\Notifications\WhatsNextNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendWhatsNextNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(
        public int $userId
    ) {}

    public function handle(): void
    {
        $user = User::find($this->userId);

        if (! $user) {
            Log::warning("User not found for what's next notification: {$this->userId}");

            return;
        }

        try {
            $user->notify(new WhatsNextNotification);
            Log::info("What's next notification sent to user {$this->userId}");
        } catch (\Exception $e) {
            Log::error("Failed to send what's next notification to user {$this->userId}: ".$e->getMessage());
            throw $e;
        }
    }
}
