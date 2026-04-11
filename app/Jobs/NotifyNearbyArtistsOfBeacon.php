<?php

namespace App\Jobs;

use App\Enums\UserTypes;
use App\Models\TattooLead;
use App\Models\User;
use App\Notifications\TattooBeaconNotification;
use App\Services\ArtistService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
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

    public function handle(ArtistService $artistService): void
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

        // Find artists near the lead's location (or fall back to client location)
        $artists = $this->findNearbyArtists($lead, $client, $artistService);

        if ($artists->isEmpty()) {
            Log::info("No nearby artists found for lead {$this->leadId}");
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

    private function findNearbyArtists(TattooLead $lead, User $client, ArtistService $artistService): Collection
    {
        // Use Elasticsearch geo_distance when lead has coordinates
        if ($lead->lat && $lead->lng) {
            $radius = $lead->radius ?? 50;
            $unit = $lead->radius_unit === 'km' ? 'km' : 'mi';

            return $artistService->getNearby(
                (float) $lead->lat,
                (float) $lead->lng,
                "{$radius}{$unit}",
            );
        }

        // Fall back to string-based location matching when no coordinates exist
        if ($client->location) {
            $city = trim(explode(',', $client->location)[0]);

            return User::where('type_id', UserTypes::ARTIST_TYPE_ID)
                ->where(function ($q) use ($client, $city) {
                    $q->where('location', $client->location)
                        ->orWhere('location', 'LIKE', '%' . $city . '%');
                })
                ->limit(50)
                ->get();
        }

        return new Collection();
    }
}
