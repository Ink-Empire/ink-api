<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PruneSignupIps extends Command
{
    /**
     * A signup address answers "did these two accounts come from one place"
     * for a report that arrives days or weeks after the accounts were made.
     * Past that window it is personal data with no remaining purpose, so it
     * is cleared rather than kept.
     */
    public const RETENTION_DAYS = 90;

    protected $signature = 'signups:prune-ips {--dry-run : Report what would be cleared without making changes}';

    protected $description = 'Clear signup IP addresses recorded more than '.self::RETENTION_DAYS.' days ago';

    public function handle(): int
    {
        $cutoff = now()->subDays(self::RETENTION_DAYS);

        $query = User::whereNotNull('signup_ip')
            ->where('created_at', '<', $cutoff);

        $count = $query->count();

        if ($count === 0) {
            $this->line('No signup IPs past retention.');

            return Command::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->line("Would clear {$count} signup IP(s) recorded before {$cutoff->toDateString()}.");

            return Command::SUCCESS;
        }

        // toBase() drops to the query builder, skipping the model events that
        // would otherwise re-index every one of these artists in
        // Elasticsearch over a field that is not indexed.
        //
        // users.updated_at carries ON UPDATE CURRENT_TIMESTAMP, so MySQL bumps
        // it on any write unless the statement sets it. A retention sweep is
        // not an account change, so it is pinned to its own value.
        $cleared = $query->toBase()->update([
            'signup_ip' => null,
            'updated_at' => DB::raw('updated_at'),
        ]);

        Log::info('Pruned signup IPs past retention', [
            'cleared' => $cleared,
            'retention_days' => self::RETENTION_DAYS,
        ]);

        $this->line("Cleared {$cleared} signup IP(s).");

        return Command::SUCCESS;
    }
}
