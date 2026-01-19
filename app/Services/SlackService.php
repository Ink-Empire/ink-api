<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SlackService
{
    protected ?string $webhookUrl;

    public function __construct()
    {
        $this->webhookUrl = config('services.slack.webhook_url');
    }

    public function send(string $message, array $blocks = []): bool
    {
        if (empty($this->webhookUrl)) {
            Log::warning('Slack webhook URL not configured');
            return false;
        }

        try {
            $payload = ['text' => $message];

            if (!empty($blocks)) {
                $payload['blocks'] = $blocks;
            }

            $response = Http::post($this->webhookUrl, $payload);

            if (!$response->successful()) {
                Log::error('Slack notification failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return false;
            }

            return true;
        } catch (\Exception $e) {
            Log::error('Slack notification error', [
                'message' => $e->getMessage(),
            ]);
            return false;
        }
    }

    public function notifyNewUser(\App\Models\User $user): bool
    {
        $userType = $user->type_id === \App\Enums\UserTypes::ARTIST_TYPE_ID ? 'Artist' : 'Client';
        $timestamp = $user->created_at->format('M j, Y \a\t g:i A');

        $message = "New User Signup!\n"
            . "━━━━━━━━━━━━━━━━━━\n"
            . "*Name:* {$user->name}\n"
            . "*Email:* {$user->email}\n"
            . "*Type:* {$userType}\n"
            . ($user->location ? "*Location:* {$user->location}\n" : "")
            . "*Signed up:* {$timestamp}";

        return $this->send($message);
    }
}
