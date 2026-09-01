<?php

namespace App\Console\Commands;

use App\Exceptions\CalendarReauthRequiredException;
use App\Models\CalendarConnection;
use App\Services\GoogleCalendarService;
use App\Services\SlackService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Exchanges a refresh token for an access token so the Google OAuth client is
 * not deleted for inactivity.
 *
 * Google deletes OAuth clients that go five months without a sign-in or a token
 * exchange. Calendar sync only talks to Google when someone has a calendar
 * connected, so a quiet period on the platform is enough to put the client on
 * the deletion list. This performs one deliberate exchange on a schedule so the
 * client stays in use regardless of platform traffic.
 */
class KeepGoogleOAuthClientAlive extends Command
{
    protected $signature = 'google:keepalive';

    protected $description = 'Refresh a Google access token so the OAuth client is not deleted for inactivity';

    public function __construct(
        protected GoogleCalendarService $googleCalendar,
        protected SlackService $slack
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $clientId = (string) config('services.google.client_id');

        if ($clientId === '') {
            return $this->fail('No GOOGLE_CLIENT_ID is configured, so nothing is keeping the OAuth client alive.');
        }

        $connection = $this->connection();

        if (! $connection) {
            return $this->fail(
                "No calendar connection is available to refresh.\n"
                ."*Project:* {$this->projectNumber($clientId)}"
            );
        }

        try {
            $this->googleCalendar->refreshToken($connection);
        } catch (CalendarReauthRequiredException $e) {
            return $this->fail(
                "Connection {$connection->id} ({$connection->provider_email}) can no longer refresh and needs reconnecting.\n"
                ."*Project:* {$this->projectNumber($clientId)}",
                ['connection_id' => $connection->id]
            );
        } catch (\Throwable $e) {
            return $this->fail(
                "Token refresh failed for connection {$connection->id} ({$connection->provider_email}).\n"
                ."*Project:* {$this->projectNumber($clientId)}\n"
                ."*Error:* {$e->getMessage()}",
                ['connection_id' => $connection->id, 'error' => $e->getMessage()]
            );
        }

        $this->info("Refreshed token for connection {$connection->id} ({$connection->provider_email}).");
        Log::info('google:keepalive: token exchanged', [
            'connection_id' => $connection->id,
            'project' => $this->projectNumber($clientId),
        ]);

        return Command::SUCCESS;
    }

    /**
     * Every failure here means the OAuth client is accruing inactivity again,
     * and Google deletes it silently once it has accrued enough. Nobody reads
     * the logs looking for that, so it goes to the ops channel.
     *
     * Unlike the health check this posts every time rather than on state
     * change. It runs weekly, so a persistent failure is one message a week
     * rather than the hourly noise the health check has to suppress.
     */
    private function fail(string $reason, array $context = []): int
    {
        $this->error($reason);

        Log::error('google:keepalive: '.$reason, $context);

        $this->slack->notifyOps(
            'Google OAuth keepalive failed',
            "{$reason}\n\nThe OAuth client is accruing inactivity. Google deletes clients "
            .'that go five months without a token exchange, which would break every '
            .'connected calendar at once.'
        );

        return Command::FAILURE;
    }

    /**
     * The connection to refresh: the designated one when configured, otherwise
     * the most recently connected account that can still refresh.
     */
    private function connection(): ?CalendarConnection
    {
        $designatedId = config('services.google.keepalive_connection_id');

        if ($designatedId) {
            return CalendarConnection::whereKey($designatedId)
                ->whereNotNull('refresh_token')
                ->first();
        }

        // Two connections created in the same second would otherwise leave the
        // choice to whatever order MySQL happens to return, so the key breaks
        // the tie and the same connection is picked every run.
        return CalendarConnection::where('provider', 'google')
            ->where('requires_reauth', false)
            ->whereNotNull('refresh_token')
            ->latest('created_at')
            ->latest('id')
            ->first();
    }

    /**
     * The leading digits of a client id are its Google Cloud project number,
     * which is what identifies the project this is keeping alive.
     */
    private function projectNumber(string $clientId): string
    {
        return explode('-', $clientId)[0];
    }
}
