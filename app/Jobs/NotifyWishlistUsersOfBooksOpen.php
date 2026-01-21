<?php

namespace App\Jobs;

use App\Models\ArtistWishlist;
use App\Models\User;
use App\Notifications\BooksOpenNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class NotifyWishlistUsersOfBooksOpen implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;

    public function __construct(
        public int $artistId
    ) {}

    public function handle(): void
    {
        $artist = User::find($this->artistId);

        if (!$artist) {
            Log::warning("Artist not found for books open notification: {$this->artistId}");
            return;
        }

        // Find all wishlist entries for this artist where notifications are enabled
        // and they haven't been notified yet
        $wishlistEntries = ArtistWishlist::where('artist_id', $this->artistId)
            ->where('notify_booking_open', true)
            ->whereNull('notified_at')
            ->get();

        if ($wishlistEntries->isEmpty()) {
            Log::info("No wishlist users to notify for artist {$this->artistId}");
            return;
        }

        $notifiedCount = 0;

        foreach ($wishlistEntries as $entry) {
            $user = User::find($entry->user_id);

            if (!$user || !$user->email) {
                continue;
            }

            try {
                $user->notify(new BooksOpenNotification($artist));

                // Update notified_at timestamp
                $entry->update(['notified_at' => now()]);

                $notifiedCount++;
            } catch (\Exception $e) {
                Log::error("Failed to send books open notification to user {$entry->user_id}: " . $e->getMessage());
            }
        }

        Log::info("Sent books open notifications for artist {$this->artistId} to {$notifiedCount} users");
    }
}
