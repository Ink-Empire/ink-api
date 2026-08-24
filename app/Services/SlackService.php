<?php

namespace App\Services;

use App\Enums\UserTypes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SlackService
{
    protected ?string $webhookUrl;
    protected ?string $supportWebhookUrl;

    /**
     * Render an instant so Slack localises it to each viewer's own timezone.
     *
     * Timestamps are stored in UTC, so formatting them directly shows UTC to
     * everyone regardless of where they are. Slack's date syntax takes a unix
     * timestamp and renders it per viewer; the text after the pipe is the
     * fallback for clients that cannot resolve it.
     */
    protected function slackTime(
        \DateTimeInterface $when,
        string $slackFormat = '{date_short_pretty} at {time}',
        string $fallbackFormat = 'M j, Y \a\t g:i A T'
    ): string {
        $moment = Carbon::instance($when);

        return '<!date^' . $moment->getTimestamp() . '^' . $slackFormat . '|' . $moment->format($fallbackFormat) . '>';
    }

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

    public function notifyEmailInboundSignup(\App\Models\User $user, int $photoCount): bool
    {
        $webhookUrl = config('services.slack.new_contact_webhook');

        if (empty($webhookUrl)) {
            Log::warning('Slack new_contact_webhook not configured');
            return false;
        }

        $message = "New artist via email upload\n"
            . "━━━━━━━━━━━━━━━━━━\n"
            . "*Name:* {$user->name}\n"
            . "*Email:* {$user->email}\n"
            . "*Photos uploaded:* {$photoCount}\n"
            . "*Account expires if no login:* " . $this->slackTime(now()->addDays(14), '{date_short}', 'M j, Y') . "\n"
            . "_Provisional account — awaiting first login_";

        try {
            $response = \Illuminate\Support\Facades\Http::post($webhookUrl, ['text' => $message]);

            if (!$response->successful()) {
                Log::error('Slack email inbound signup notification failed', [
                    'status' => $response->status(),
                ]);
                return false;
            }

            return true;
        } catch (\Exception $e) {
            Log::error('Slack email inbound signup notification error', ['message' => $e->getMessage()]);
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

        $timestamp = $this->slackTime($user->created_at);

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

        $timestamp = $this->slackTime(now());

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
