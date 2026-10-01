<?php

namespace App\Console\Commands;

use App\Enums\UserTypes;
use App\Jobs\SendWhatsNextNotification;
use App\Models\User;
use App\Notifications\WhatsNextNotification;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Spatie\NotificationLog\Models\NotificationLogItem;

class SendWhatsNextEmails extends Command
{
    protected $signature = 'emails:whats-next
                            {--dry-run : List who would be emailed without queueing anything}
                            {--limit=200 : Stop after this many accounts in one run}';

    protected $description = "Queue the what's next email for accounts that signed up a week ago and came back";

    /**
     * Nobody who signed up before this date is ever eligible.
     *
     * Without it the first run would treat every account in the table as due
     * and email most of the list at once, which is the accidental version of
     * the backfill that is still an open decision. Moving this date backwards
     * is how you would deliberately include older accounts, and it should be a
     * decision rather than a side effect.
     */
    public const ELIGIBLE_FROM = '2026-10-01';

    /**
     * How long after signup the email goes out.
     */
    public const DAYS_AFTER_SIGNUP = 7;

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = max(1, (int) $this->option('limit'));

        $users = $this->eligible()->limit($limit)->get();

        if ($users->isEmpty()) {
            $this->info('Nobody is due.');

            return self::SUCCESS;
        }

        foreach ($users as $user) {
            if ($dryRun) {
                $this->line("would queue: {$user->id} {$user->email} (type {$user->type_id}, signed up {$user->created_at})");

                continue;
            }

            SendWhatsNextNotification::dispatch($user->id);
        }

        $verb = $dryRun ? 'would queue' : 'queued';
        $this->info("{$verb} ".$users->count().' account(s).');

        return self::SUCCESS;
    }

    /**
     * Signed up at least a week ago, verified, came back at least once, and
     * has not already had this email.
     */
    private function eligible(): Builder
    {
        return User::query()
            ->whereDate('created_at', '>=', self::ELIGIBLE_FROM)
            ->where('created_at', '<=', now()->subDays(self::DAYS_AFTER_SIGNUP))
            ->whereNotNull('email_verified_at')
            // Coming back to sign in is the sign that the account is real and
            // worth a second email.
            ->whereNotNull('last_login_at')
            ->where('email_unsubscribed', false)
            // A type with no copy would throw inside the queued job, so it
            // never gets picked up in the first place.
            ->whereIn('type_id', [
                UserTypes::CLIENT_TYPE_ID,
                UserTypes::ARTIST_TYPE_ID,
                UserTypes::STUDIO_TYPE_ID,
            ])
            ->whereNotIn('id', $this->alreadySent())
            ->orderBy('id');
    }

    /**
     * notification_log_items already records every send, so it is the record
     * of who has had this. A second flag on users would be a second source of
     * truth and the two would drift.
     *
     * @return \Illuminate\Support\Collection<int, int>
     */
    private function alreadySent()
    {
        return NotificationLogItem::query()
            ->where('notification_type', WhatsNextNotification::class)
            ->where('notifiable_type', User::class)
            ->whereNotNull('notifiable_id')
            ->distinct()
            ->pluck('notifiable_id');
    }
}
