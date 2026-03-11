<?php

namespace App\Http\Controllers;

use App\Enums\ArtistTattooApprovalStatus;
use App\Jobs\IndexTattooJob;
use App\Models\ArtistInvitation;
use App\Models\Tattoo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ArtistInvitationController extends Controller
{
    /**
     * Show invitation details by token (public).
     */
    public function show(string $token): JsonResponse
    {
        $invitation = ArtistInvitation::where('token', $token)
            ->with(['tattoo.primary_image', 'tattoo.images', 'invitedBy'])
            ->first();

        if (!$invitation) {
            return $this->returnErrorResponse('Invitation not found', 404);
        }

        if ($invitation->claimed_at) {
            return response()->json([
                'invitation' => [
                    'artist_name' => $invitation->artist_name,
                    'claimed' => true,
                ],
            ]);
        }

        // For phone-only invitations, scope to this specific invitation; for email, find all with same email
        $unclaimedQuery = $invitation->email
            ? ArtistInvitation::where('email', $invitation->email)->whereNull('claimed_at')
            : ArtistInvitation::where('id', $invitation->id)->whereNull('claimed_at');

        $unclaimedCount = $unclaimedQuery->count();

        $tattoos = (clone $unclaimedQuery)
            ->with(['tattoo.primary_image', 'tattoo.images'])
            ->get()
            ->pluck('tattoo')
            ->filter();

        return response()->json([
            'invitation' => [
                'artist_name' => $invitation->artist_name,
                'studio_name' => $invitation->studio_name,
                'location' => $invitation->location,
                'email' => $invitation->email,
                'invited_by' => $invitation->invitedBy?->name,
                'claimed' => false,
                'unclaimed_tattoo_count' => $unclaimedCount,
                'tattoos' => $tattoos->map(fn ($tattoo) => [
                    'id' => $tattoo->id,
                    'title' => $tattoo->title,
                    'primary_image' => $tattoo->primary_image?->uri,
                    'images' => $tattoo->images->pluck('uri'),
                ])->values(),
            ],
        ]);
    }

    /**
     * Claim all tattoos for this invitation's email (authenticated).
     */
    public function claim(Request $request, string $token): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return $this->returnErrorResponse('You must be logged in to claim tattoos', 401);
        }

        $invitation = ArtistInvitation::where('token', $token)
            ->whereNull('claimed_at')
            ->first();

        if (!$invitation) {
            return $this->returnErrorResponse('Invitation not found or already claimed', 404);
        }

        // Verify the user's email matches the invitation (skip for phone-only invitations)
        if ($invitation->email && strtolower($user->email) !== strtolower($invitation->email)) {
            return response()->json([
                'error' => 'Email mismatch',
                'message' => 'Please sign in with the email address this invitation was sent to.',
                'expected_email' => $invitation->email,
            ], 403);
        }

        // For phone-only invitations, claim just this one; for email invitations, claim all with same email
        if ($invitation->email) {
            $invitations = ArtistInvitation::where('email', $invitation->email)
                ->whereNull('claimed_at')
                ->get();
        } else {
            $invitations = collect([$invitation]);
        }

        $claimedTattooIds = [];

        foreach ($invitations as $inv) {
            $tattoo = Tattoo::find($inv->tattoo_id);
            if (!$tattoo) {
                continue;
            }

            $tattoo->artist_id = $user->id;
            $tattoo->approval_status = ArtistTattooApprovalStatus::APPROVED;
            $tattoo->is_visible = true;
            $tattoo->attributed_artist_name = null;
            $tattoo->attributed_studio_name = null;
            $tattoo->attributed_location = null;
            $tattoo->save();

            IndexTattooJob::dispatch($tattoo->id);
            Cache::forget("es:tattoo:{$tattoo->id}");

            $claimedTattooIds[] = $tattoo->id;

            $inv->claimed_by_user_id = $user->id;
            $inv->claimed_at = now();
            $inv->save();
        }

        return response()->json([
            'success' => true,
            'claimed_count' => count($claimedTattooIds),
            'tattoo_ids' => $claimedTattooIds,
        ]);
    }
}
