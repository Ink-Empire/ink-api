<?php

namespace App\Console\Commands;

use App\Models\CalendarConnection;
use App\Notifications\CalendarDisconnectedNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Spatie\NotificationLog\Models\NotificationLogItem;

/**
 * Emails artists whose calendar was disconnected before the notice existed.
 *
 * GoogleCalendarService only sends CalendarDisconnectedNotification on the
 * transition into requires_reauth, so anyone already flagged when that shipped
 * is past the transition and never hears anything. Their calendar has silently
 * stopped syncing, which is how a double booking happens.
 */
class BackfillCalendarReauthNotices extends Command
{
    protected $signature = 'calendar:backfill-reauth-notices
                           {--dry-run : List who would be emailed without sending anything}
                           {--count= : Limit the number of connections to process}';

    protected $description = 'Email artists flagged requires_reauth before the disconnection notice existed';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $count = $this->option('count') ? (int) $this->option('count') : null;

        $connections = $this->pending($count);

        if ($connections->isEmpty()) {
            $this->info('No connections need a backfilled notice.');

            return Command::SUCCESS;
        }

        $this->table(
            ['Connection', 'Artist', 'Email', 'Google account'],
            $connections->map(fn ($connection) => [
                $connection->id,
                $connection->user?->name ?? $connection->user?->username ?? 'MISSING USER',
                $connection->user?->email ?? '-',
                $connection->provider_email,
            ])
        );

        if ($dryRun) {
            $this->warn("DRY RUN. {$connections->count()} artist(s) would be emailed. Re-run without --dry-run to send.");

            return Command::SUCCESS;
        }

        // Real mail to real customers, so this never sends on a stray
        // invocation. Under --no-interaction the answer is no and nothing goes.
        if (! $this->confirm("Send a disconnection email to {$connections->count()} artist(s)?", false)) {
            $this->info('Nothing sent.');

            return Command::SUCCESS;
        }

        return $this->send($connections);
    }

    private function send($connections): int
    {
        $sent = 0;
        $skipped = 0;

        foreach ($connections as $connection) {
            if (! $connection->user) {
                $this->warn("Connection {$connection->id} has no user, skipping.");
                $skipped++;

                continue;
            }

            $connection->user->notify(new CalendarDisconnectedNotification($connection));

            Log::info('calendar:backfill-reauth-notices: notice sent', [
                'connection_id' => $connection->id,
                'user_id' => $connection->user->id,
            ]);

            $sent++;
        }

        $this->info("Sent {$sent} notice(s), skipped {$skipped}.");

        return Command::SUCCESS;
    }

    /**
     * Flagged connections whose owner has never had the notice. The log is the
     * record of what actually went out, so re-running this cannot email anyone
     * a second time.
     */
    private function pending(?int $count)
    {
        // Artist extends User on the same table, so ids are shared between the
        // two notifiable types. Matching on id alone rather than on type keeps
        // this on the safe side of a double send.
        $alreadyNotified = NotificationLogItem::query()
            ->where('notification_type', CalendarDisconnectedNotification::class)
            ->pluck('notifiable_id')
            ->unique()
            ->all();

        $query = CalendarConnection::query()
            ->where('provider', 'google')
            ->where('requires_reauth', true)
            ->whereNotIn('user_id', $alreadyNotified)
            ->with('user')
            ->orderBy('id');

        if ($count) {
            $query->limit($count);
        }

        return $query->get();
    }
}
