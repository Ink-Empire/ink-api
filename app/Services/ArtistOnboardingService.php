<?php

namespace App\Services;

use App\Enums\UserTypes;
use App\Models\BulkUpload;
use App\Models\BulkUploadItem;
use App\Models\Studio;
use App\Models\User;
use App\Notifications\ArtistJoinRequestNotification;
use App\Notifications\InboundEmailReceiptNotification;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Creates artist accounts from material somebody else sent in, and puts their
 * images into the review queue.
 *
 * This started as private methods on FetchInboundEmails. It lives here so the
 * admin panel can onboard an artist whose photos arrived by some other route,
 * without the temp password and verification rules being written twice and
 * drifting apart.
 */
class ArtistOnboardingService
{
    public function __construct(
        private ImageService $imageService
    ) {}

    /**
     * The artist for this address, creating a provisional account if there
     * isn't one.
     *
     * Returns the user, the plaintext temp password when an account was just
     * created, and whether it was created. The password is returned rather than
     * stored so it can go in the one email that hands it over.
     */
    public function findOrCreateArtist(string $email, string $name): array
    {
        $existing = User::where('email', $email)->first();

        if ($existing) {
            return [$existing, null, false];
        }

        $tempPassword = strtoupper(Str::random(4)).'-'.strtoupper(Str::random(4)).'-'.rand(1000, 9999);

        $username = $this->generateUniqueUsername($email);

        $user = User::create([
            'name' => $name,
            'email' => $email,
            'username' => $username,
            'slug' => $username,
            'password' => Hash::make($tempPassword),
            'type_id' => UserTypes::ARTIST_TYPE_ID,
            'location' => '',
            'has_accepted_toc' => false,
            'has_accepted_privacy_policy' => false,
            'force_password_reset' => true,
        ]);

        // Marked verified before Registered fires so the verification email is
        // skipped. Whoever sent the photos proved the address works, and the
        // artist gets one email rather than two.
        $user->markEmailAsVerified();

        event(new Registered($user));

        return [$user, $tempPassword, true];
    }

    /**
     * Stores images against a new bulk upload for the artist to review.
     *
     * Each image is a ['content' => base64, 'mime' => string, 'filename' =>
     * ?string, 'size' => ?int]. A single bad file is logged and skipped rather
     * than losing the rest of the batch.
     */
    public function ingestImages(User $artist, array $images, string $source): BulkUpload
    {
        $bulkUpload = BulkUpload::create([
            'artist_id' => $artist->id,
            'source' => $source,
            'status' => 'processing',
            'total_images' => count($images),
        ]);

        $processed = 0;

        foreach (array_values($images) as $index => $image) {
            try {
                $ext = $this->extensionFromMime($image['mime'] ?? 'image/jpeg');
                $baseFilename = "tattoo_{$artist->id}_".now()->format('YmdHis')."_{$index}_".Str::random(8).".{$ext}";

                $stored = $this->imageService->processImage($image['content'], $baseFilename);

                BulkUploadItem::create([
                    'bulk_upload_id' => $bulkUpload->id,
                    'image_id' => $stored->id,
                    'zip_path' => $image['filename'] ?? $baseFilename,
                    'file_size_bytes' => $image['size'] ?? null,
                    'is_cataloged' => true,
                    'is_processed' => true,
                    'sort_order' => $index,
                ]);

                $processed++;
            } catch (\Throwable $e) {
                Log::error('ArtistOnboardingService: failed to store image', [
                    'user_id' => $artist->id,
                    'filename' => $image['filename'] ?? 'unknown',
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $bulkUpload->update(['status' => $processed > 0 ? 'ready' : 'failed']);
        $bulkUpload->updateCounts();

        return $bulkUpload;
    }

    /**
     * The whole thing: find or create the artist, store their images, and send
     * the one email that carries their credentials and a link to review.
     *
     * Returns [User, BulkUpload, int $processed, bool $isNewAccount].
     */
    public function onboard(string $email, string $name, array $images, string $source): array
    {
        [$artist, $tempPassword, $isNewAccount] = $this->findOrCreateArtist($email, $name);

        $bulkUpload = $this->ingestImages($artist, $images, $source);
        $processed = $bulkUpload->items()->count();

        if ($processed > 0) {
            $artist->notify(new InboundEmailReceiptNotification($bulkUpload, $processed, $isNewAccount, $tempPassword));
        }

        return [$artist, $bulkUpload, $processed, $isNewAccount];
    }

    /**
     * Records where the artist is, only when the coordinates came with it.
     *
     * location and location_lat_long are separate columns and the Places picker
     * fills both. A location string on its own would leave the artist out of
     * proximity search, which reads the coordinates, and out of reach of
     * users:backfill-timezones, which derives the timezone from them. An artist
     * with no timezone gets their bookings written to Google in UTC.
     *
     * An existing location is left alone. The artist may have set it themselves
     * and this should not overwrite that.
     */
    public function applyLocation(User $artist, ?string $location, ?string $latLong): void
    {
        if (! $location || ! $latLong || $artist->location_lat_long) {
            return;
        }

        $artist->update([
            'location' => $location,
            'location_lat_long' => $latLong,
        ]);
    }

    /**
     * Attaches the artist to a studio as an unverified join request.
     *
     * Recorded as initiated by the artist, matching registration, because the
     * artist is who asked. initiated_by only takes 'artist' or 'studio', and
     * both the studio's pending queue and the artist's invitations filter on
     * it, so a third value here would make the affiliation invisible to both.
     *
     * The studio owner is told, the same as when an artist signs up and picks
     * a studio themselves. Failing to notify does not undo the affiliation.
     */
    public function affiliateWithStudio(User $artist, int $studioId): ?Studio
    {
        $studio = Studio::find($studioId);

        if (! $studio) {
            return null;
        }

        $studio->artists()->syncWithoutDetaching([
            $artist->id => [
                'is_verified' => false,
                'initiated_by' => 'artist',
            ],
        ]);

        if ($studio->owner) {
            try {
                $studio->owner->notify(new ArtistJoinRequestNotification($artist, $studio));
            } catch (\Exception $e) {
                Log::warning('Failed to send artist join request notification', [
                    'artist_id' => $artist->id,
                    'studio_id' => $studio->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $studio;
    }

    public function generateUniqueUsername(string $email): string
    {
        $prefix = explode('@', $email)[0];
        $base = strtolower(preg_replace('/[^a-zA-Z0-9._]/', '', $prefix));
        $base = $base ?: 'artist';
        $base = substr($base, 0, 28);
        $username = $base;
        $counter = 1;

        while (User::where('username', $username)->orWhere('slug', $username)->exists()) {
            $username = $base.$counter++;
        }

        return $username;
    }

    private function extensionFromMime(string $mimeType): string
    {
        return match ($mimeType) {
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            default => 'jpg',
        };
    }
}
