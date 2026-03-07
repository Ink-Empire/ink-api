<?php

namespace App\Services;

use App\Enums\UserTypes;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SlackService
{
    protected ?string $webhookUrl;
    protected ?string $supportWebhookUrl;

    public function __construct()
    {
        $this->webhookUrl = config('services.slack.webhook_url');
        $this->supportWebhookUrl = config('services.slack.support_webhook_url');
    }

    public function send(string $message, array $blocks = [], ?string $webhookUrl = null): bool
    {
        $url = $webhookUrl ?? $this->webhookUrl;

        if (empty($url)) {
            Log::warning('Slack webhook URL not configured');
            return false;
        }

        try {
            $payload = ['text' => $message];

            if (!empty($blocks)) {
                $payload['blocks'] = $blocks;
            }

            $response = Http::post($url, $payload);

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
        if (app()->environment() !== 'production') {
            return false;
        }

        //switch based on id
        $userType = match ($user->type_id) {
            1 => UserTypes::CLIENT,
            2 => UserTypes::ARTIST,
            3 => UserTypes::STUDIO,
            default => 'Unknown type',
        };

        $timestamp = $user->created_at->format('M j, Y \a\t g:i A');

        $message = "New User Signup!\n"
            . "━━━━━━━━━━━━━━━━━━\n"
            . "*Name:* {$user->name}\n"
            . "*Email:* {$user->email}\n"
            . "*Type:* {$userType}\n"
            . ($user->location ? "*Location:* {$user->location}\n" : "")
            . ($user->signup_platform ? "*Platform:* {$user->signup_platform}\n" : "")
            . "*Signed up:* {$timestamp}";

        return $this->send($message);
    }

    public function notifySupportRequest(\App\Models\User $user, ?string $messageContent = null): bool
    {
        if (app()->environment() !== 'production') {
            return false;
        }

        $timestamp = now()->format('M j, Y \a\t g:i A');

        $message = "New Support Request\n"
            . "━━━━━━━━━━━━━━━━━━\n"
            . "*From:* {$user->name}\n"
            . "*Email:* {$user->email}\n"
            . "*Username:* {$user->username}\n"
            . "*Time:* {$timestamp}\n";

        if ($messageContent) {
            $message .= "*Message:* {$messageContent}\n";
        }

        $message .= "Check the inbox for info@getinked.in to respond.";

        return $this->send($message, [], $this->supportWebhookUrl);
    }
}
