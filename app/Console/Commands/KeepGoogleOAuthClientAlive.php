<?php

namespace App\Console\Commands;

use App\Exceptions\CalendarReauthRequiredException;
use App\Models\CalendarConnection;
use App\Services\GoogleCalendarService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Exchanges a refresh token for an access token so the Google OAuth client is
 * not deleted for inactivity.
 *
 * Google deletes OAuth clients that go five months without a sign-in or a token
 * exchange. Calendar sync only touches Google when someone has a calendar
 * connected, so a quiet period on the platform is enough to put the client on
 * the deletion list. This performs one deliberate exchange on a schedule so the
 * client stays in use regardless of platform traffic.
 */
class KeepGoogleOAuthClientAlive extends Command
{
    protected $signature = 'google:keepalive';

    protected $description = 'Refresh a Google access token so the OAuth client is not deleted for inactivity';

    public function handle(GoogleCalendarService $googleCalendar): int
    {
        $clientId = (string) config('services.google.client_id');

        if ($clientId === '') {
            $this->error('No GOOGLE_CLIENT_ID configured, nothing to keep alive.');
            Log::error('google:keepalive: no client id configured');

            return Command::FAILURE;
        }

        $connection = $this->connection();

        if (!$connection) {
            $this->error('No usable calendar connection to refresh. The OAuth client is at risk of deletion.');
            Log::error('google:keepalive: no usable calendar connection', [
                'client_id' => $this->maskedClientId($clientId),
            ]);

            return Command::FAILURE;
        }

        try {
            $googleCalendar->refreshToken($connection);
        } catch (CalendarReauthRequiredException $e) {
            $this->error("Connection {$connection->id} ({$connection->provider_email}) needs reconnecting, no token was exchanged.");
            Log::error('google:keepalive: connection requires reauth', [
                'connection_id' => $connection->id,
                'client_id'     => $this->maskedClientId($clientId),
            ]);

            return Command::FAILURE;
        } catch (\Throwable $e) {
            $this->error("Token refresh failed: {$e->getMessage()}");
            Log::error('google:keepalive: token refresh failed', [
                'connection_id' => $connection->id,
                'client_id'     => $this->maskedClientId($clientId),
                'error'         => $e->getMessage(),
            ]);

            return Command::FAILURE;
        }

        $this->info("Refreshed token for connection {$connection->id} ({$connection->provider_email}).");
        Log::info('google:keepalive: token exchanged', [
            'connection_id' => $connection->id,
            'client_id'     => $this->maskedClientId($clientId),
        ]);

        return Command::SUCCESS;
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

        return CalendarConnection::where('provider', 'google')
            ->where('requires_reauth', false)
            ->whereNotNull('refresh_token')
            ->latest('created_at')
            ->first();
    }

    /**
     * The leading digits of a client id are its Google Cloud project number,
     * which is what identifies the project this is keeping alive.
     */
    private function maskedClientId(string $clientId): string
    {
        return explode('-', $clientId)[0];
    }
}
