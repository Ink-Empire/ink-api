<?php

namespace App\Http\Controllers;

use App\Jobs\NotifyNearbyArtistsOfBeacon;
use App\Models\TattooLead;
use App\Notifications\TattooBeaconNotification;
use App\Services\NotificationStatsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\NotificationLog\Models\NotificationLogItem;

class TattooLeadController extends Controller
{
    /**
     * Get the current user's active lead status.
     */
    public function status(Request $request): JsonResponse
    {
        $user = $request->user();
        $lead = TattooLead::where('user_id', $user->id)
            ->where('is_active', true)
            ->first();

        if (!$lead) {
            return response()->json([
                'has_lead' => false,
                'is_active' => false,
                'lead' => null,
                'artists_notified' => 0,
            ]);
        }

        // Count how many artists were notified about this lead
        $artistsNotified = NotificationLogItem::query()
            ->whereJsonContains('extra->event_type', TattooBeaconNotification::EVENT_TYPE)
            ->whereJsonContains('extra->reference_id', $lead->id)
            ->count();

        return response()->json([
            'has_lead' => true,
            'is_active' => $lead->is_active,
            'artists_notified' => $artistsNotified,
            'lead' => [
                'id' => $lead->id,
                'timing' => $lead->timing,
                'interested_by' => $lead->interested_by?->toDateString(),
                'allow_artist_contact' => $lead->allow_artist_contact,
                'style_ids' => $lead->style_ids ?? [],
                'tag_ids' => $lead->tag_ids ?? [],
                'custom_themes' => $lead->custom_themes ?? [],
                'description' => $lead->description,
                'is_active' => $lead->is_active,
            ],
        ]);
    }

    /**
     * Create a new tattoo lead for the current user.
     * Deactivates any existing active leads first.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'timing' => 'nullable|in:week,month,year',
            'allow_artist_contact' => 'boolean',
            'style_ids' => 'nullable|array',
            'style_ids.*' => 'integer',
            'tag_ids' => 'nullable|array',
            'tag_ids.*' => 'integer',
            'custom_themes' => 'nullable|array',
            'custom_themes.*' => 'string|max:100',
            'description' => 'nullable|string|max:2000',
        ]);

        $user = $request->user();

        // Deactivate any existing active leads
        TattooLead::where('user_id', $user->id)
            ->where('is_active', true)
            ->update(['is_active' => false]);

        // Parse user's location for indexed geo queries
        $lat = null;
        $lng = null;
        if ($user->location_lat_long) {
            [$lat, $lng] = array_map('floatval', explode(',', $user->location_lat_long));
        }

        // Create new lead
        $lead = TattooLead::create([
            'user_id' => $user->id,
            'timing' => $request->input('timing'),
            'interested_by' => TattooLead::calculateInterestedBy($request->input('timing')),
            'allow_artist_contact' => $request->input('allow_artist_contact', false),
            'style_ids' => $request->input('style_ids'),
            'tag_ids' => $request->input('tag_ids'),
            'custom_themes' => $request->input('custom_themes'),
            'description' => $request->input('description'),
            'is_active' => true,
            'lat' => $lat,
            'lng' => $lng,
        ]);

        // Notify nearby artists if the user allows artist contact
        if ($lead->allow_artist_contact) {
            NotifyNearbyArtistsOfBeacon::dispatch($lead->id);
        }

        return response()->json([
            'lead' => [
                'id' => $lead->id,
                'timing' => $lead->timing,
                'interested_by' => $lead->interested_by?->toDateString(),
                'allow_artist_contact' => $lead->allow_artist_contact,
                'style_ids' => $lead->style_ids ?? [],
                'tag_ids' => $lead->tag_ids ?? [],
                'custom_themes' => $lead->custom_themes ?? [],
                'description' => $lead->description,
                'is_active' => $lead->is_active,
            ],
            'message' => 'Lead saved successfully',
        ]);
    }

    /**
     * Toggle the active status of the user's lead.
     */
    public function toggle(Request $request): JsonResponse
    {
        $user = $request->user();
        $activeLead = TattooLead::where('user_id', $user->id)
            ->where('is_active', true)
            ->first();

        if (!$activeLead) {
            // Parse user's location for indexed geo queries
            $lat = null;
            $lng = null;
            if ($user->location_lat_long) {
                [$lat, $lng] = array_map('floatval', explode(',', $user->location_lat_long));
            }

            // No active lead - create a new one with minimal info
            $lead = TattooLead::create([
                'user_id' => $user->id,
                'is_active' => true,
                'allow_artist_contact' => true,
                'lat' => $lat,
                'lng' => $lng,
            ]);

            return response()->json([
                'lead' => [
                    'id' => $lead->id,
                    'is_active' => true,
                ],
                'message' => 'You are now actively looking for a tattoo',
            ]);
        }

        // Deactivate the current lead
        $activeLead->update(['is_active' => false]);

        return response()->json([
            'lead' => [
                'id' => $activeLead->id,
                'is_active' => false,
            ],
            'message' => 'You are no longer actively looking',
        ]);
    }

    /**
     * Update specific fields of the user's active lead.
     */
    public function update(Request $request): JsonResponse
    {
        $request->validate([
            'timing' => 'nullable|in:week,month,year',
            'allow_artist_contact' => 'boolean',
            'style_ids' => 'nullable|array',
            'tag_ids' => 'nullable|array',
            'custom_themes' => 'nullable|array',
            'custom_themes.*' => 'string|max:100',
            'description' => 'nullable|string|max:2000',
            'is_active' => 'boolean',
        ]);

        $user = $request->user();
        $lead = TattooLead::where('user_id', $user->id)
            ->where('is_active', true)
            ->first();

        if (!$lead) {
            return response()->json([
                'error' => 'No active lead found for this user',
            ], 404);
        }

        $updateData = [];

        if ($request->has('timing')) {
            $updateData['timing'] = $request->input('timing');
            $updateData['interested_by'] = TattooLead::calculateInterestedBy($request->input('timing'));
        }

        if ($request->has('allow_artist_contact')) {
            $updateData['allow_artist_contact'] = $request->input('allow_artist_contact');
        }

        if ($request->has('style_ids')) {
            $updateData['style_ids'] = $request->input('style_ids');
        }

        if ($request->has('tag_ids')) {
            $updateData['tag_ids'] = $request->input('tag_ids');
        }

        if ($request->has('custom_themes')) {
            $updateData['custom_themes'] = $request->input('custom_themes');
        }

        if ($request->has('description')) {
            $updateData['description'] = $request->input('description');
        }

        if ($request->has('is_active')) {
            $updateData['is_active'] = $request->input('is_active');
        }

        $lead->update($updateData);

        return response()->json([
            'lead' => [
                'id' => $lead->id,
                'timing' => $lead->timing,
                'interested_by' => $lead->interested_by?->toDateString(),
                'allow_artist_contact' => $lead->allow_artist_contact,
                'style_ids' => $lead->style_ids ?? [],
                'tag_ids' => $lead->tag_ids ?? [],
                'custom_themes' => $lead->custom_themes ?? [],
                'description' => $lead->description,
                'is_active' => $lead->is_active,
            ],
            'message' => 'Lead updated successfully',
        ]);
    }

    /**
     * Get leads for artists to reach out to.
     * Returns active leads that allow artist contact within 50 miles of the artist.
     */
    public function forArtists(Request $request): JsonResponse
    {
        $artist = $request->user();
        $limit = min($request->input('limit', 10), 50);
        $radiusMiles = 50;

        // Get artist's coordinates
        $artistCoords = $artist->location_lat_long;
        if (!$artistCoords) {
            return response()->json(['leads' => []]);
        }

        [$artistLat, $artistLng] = array_map('floatval', explode(',', $artistCoords));

        // Query leads within radius using indexed lat/lng columns
        $leads = TattooLead::with(['user'])
            ->active()
            ->contactable()
            ->withinRadius($artistLat, $artistLng, $radiusMiles)
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();

        return response()->json([
            'leads' => $leads->map(function ($lead) {
                $user = $lead->user;
                return [
                    'id' => $lead->id,
                    'timing' => $lead->timing,
                    'timing_label' => $this->getTimingLabel($lead->timing),
                    'description' => $lead->description,
                    'custom_themes' => $lead->custom_themes ?? [],
                    'tag_ids' => $lead->tag_ids ?? [],
                    'style_ids' => $lead->style_ids ?? [],
                    'created_at' => $lead->created_at->toIso8601String(),
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'username' => $user->username,
                        'email' => $user->email,
                        'image' => $user->image,
                        'location' => $user->location ?? $user->city,
                    ],
                ];
            }),
        ]);
    }

    private function getTimingLabel(?string $timing): string
    {
        return match ($timing) {
            'week' => 'This week',
            'month' => 'This month',
            'year' => 'This year',
            default => 'Flexible',
        };
    }
}
