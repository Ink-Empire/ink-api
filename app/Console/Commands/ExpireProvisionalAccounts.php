<?php

namespace App\Console\Commands;

use App\Models\BulkUpload;
use App\Models\InboundEmailLog;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ExpireProvisionalAccounts extends Command
{
    protected $signature = 'users:expire-provisional {--dry-run : Log what would be expired without making changes}';

    protected $description = 'Deactivate email-inbound provisional accounts that have not logged in within 14 days';

    public function handle(): int
    {
        $dryRun  = $this->option('dry-run');
        $cutoff  = now()->subDays(14);

        // Provisional accounts: created via email inbound (have an InboundEmailLog entry),
        // force_password_reset still true (haven't logged in and changed password),
        // and last_login_at is null (never logged in at all).
        $expired = User::where('force_password_reset', true)
            ->whereNull('last_login_at')
            ->where('created_at', '<', $cutoff)
            ->whereIn('email', InboundEmailLog::select('sender_email'))
            ->get();

        if ($expired->isEmpty()) {
            $this->line('No provisional accounts to expire.');
            return Command::SUCCESS;
        }

        $this->line("Found {$expired->count()} expired provisional account(s).");

        foreach ($expired as $user) {
            $uploadCount = BulkUpload::where('artist_id', $user->id)->count();
            $this->line("  {$user->email} (created {$user->created_at->toDateString()}, {$uploadCount} upload(s))");

            if ($dryRun) {
                continue;
            }

            try {
                BulkUpload::where('artist_id', $user->id)
                    ->each(function ($bu) {
                        $bu->items()->delete();
                        $bu->delete();
                    });

                InboundEmailLog::where('sender_email', $user->email)->delete();

                $user->delete();

                Log::info('ExpireProvisionalAccounts: account expired', [
                    'email'      => $user->email,
                    'created_at' => $user->created_at,
                ]);
            } catch (\Throwable $e) {
                $this->warn("  Failed to expire {$user->email}: {$e->getMessage()}");
                Log::error('ExpireProvisionalAccounts: failed', [
                    'email' => $user->email,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if (!$dryRun) {
            $this->info("Expired {$expired->count()} account(s).");
        }

        return Command::SUCCESS;
    }
}
