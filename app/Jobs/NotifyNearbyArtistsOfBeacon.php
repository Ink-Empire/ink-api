<?php

namespace App\Jobs;

use App\Enums\UserTypes;
use App\Models\TattooLead;
use App\Models\User;
use App\Notifications\TattooBeaconNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class NotifyNearbyArtistsOfBeacon implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;

    public function __construct(
        public int $leadId
    ) {}

    public function handle(): void
    {
        $lead = TattooLead::with('user')->find($this->leadId);

        if (!$lead || !$lead->is_active) {
            Log::warning("TattooLead not found or inactive: {$this->leadId}");
            return;
        }

        $client = $lead->user;
        if (!$client) {
            Log::warning("Client not found for lead: {$this->leadId}");
            return;
        }

        // Find artists in the same location
        $artists = $this->findNearbyArtists($client);

        if ($artists->isEmpty()) {
            Log::info("No nearby artists found for lead {$this->leadId} in location: {$client->location}");
            return;
        }

        $notifiedCount = 0;

        foreach ($artists as $artist) {
            // Don't notify the client themselves if they happen to be an artist
            if ($artist->id === $client->id) {
                continue;
            }

            if (!$artist->email) {
                continue;
            }

            try {
                $artist->notify(new TattooBeaconNotification($lead, $client));
                $notifiedCount++;
            } catch (\Exception $e) {
                Log::error("Failed to send beacon notification to artist {$artist->id}: " . $e->getMessage());
            }
        }

        Log::info("Sent beacon notifications for lead {$this->leadId} to {$notifiedCount} artists");
    }

    private function findNearbyArtists(User $client): \Illuminate\Database\Eloquent\Collection
    {
        $query = User::where('type_id', UserTypes::ARTIST_TYPE_ID);

        // Match by location if the client has one set
        if ($client->location) {
            // Simple location matching - can be enhanced with geo queries later
            $query->where(function ($q) use ($client) {
                // Exact match
                $q->where('location', $client->location)
                    // Or partial match (e.g., "Los Angeles" matches "Los Angeles, CA")
                    ->orWhere('location', 'LIKE', '%' . $this->extractCity($client->location) . '%');
            });
        }

        // Optionally filter by styles if the lead has style preferences
        // This could be added later for more targeted notifications

        return $query->limit(50)->get(); // Cap at 50 artists per notification batch
    }

    private function extractCity(string $location): string
    {
        // Extract the city from "City, State" or "City, State, Country" format
        $parts = explode(',', $location);
        return trim($parts[0]);
    }
}
