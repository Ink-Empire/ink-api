<?php

namespace App\Services;

use App\Enums\SpotlightType;
use App\Enums\StudioHoldStatus;
use App\Enums\StudioPostStatus;
use App\Enums\StudioPostType;
use App\Enums\UserTypes;
use Illuminate\Support\Facades\DB;
use App\Exceptions\StudioAlreadyOnHoldException;
use App\Exceptions\StudioNotFoundException;
use App\Exceptions\StudioNotOnHoldException;
use App\Exceptions\StudioOwnerConflictException;
use App\Http\Resources\Dashboard\ArtistDashboardResource;
use App\Http\Resources\Elastic\TattooResource;
use App\Models\Artist;
use App\Models\Image;
use App\Models\StudioAvailability;
use App\Models\Address;
use App\Models\Tattoo;
use App\Models\Studio;
use App\Models\StudioPost;
use App\Models\StudioSpotlight;
use App\Models\User;
use App\Notifications\StudioVerificationRequestNotification;
use App\Scopes\ArtistScope;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 *
 */
class StudioService
{

    /**
     * Get studio by ID or slug
     * @param $id
     */
    public function getById($id): ?Studio
    {
        if (!$id) {
            return null;
        }

        // If numeric, search by ID; otherwise search by slug
        if (is_numeric($id)) {
            return Studio::where('id', $id)->first();
        }

        return Studio::where('slug', $id)->first();
    }

    /**
     * Build a URL-safe, unique slug for a studio.
     *
     * The studio slug is the root of the public studio URL and of everything
     * nested beneath it, so every write path routes through here rather than
     * formatting a slug itself.
     */
    public function generateSlug(string $source, ?int $ignoreStudioId = null): string
    {
        $base = Str::slug($source) ?: 'studio';
        $slug = $base;
        $suffix = 2;

        while ($this->isSlugTaken($slug, $ignoreStudioId)) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }

    /**
     * Whether a slug already belongs to a different studio.
     */
    public function isSlugTaken(string $slug, ?int $ignoreStudioId = null): bool
    {
        return Studio::where('slug', $slug)
            ->when($ignoreStudioId, fn ($query) => $query->where('id', '!=', $ignoreStudioId))
            ->exists();
    }

    /**
     * Create a studio owned by the given user.
     *
     * The owner comes from the authenticated session rather than the payload.
     * The account that ends up carrying the studio has to be the one that
     * asked for it, and the type change below must not land on a stranger.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws StudioOwnerConflictException
     */
    public function createForOwner(User $owner, array $data): Studio
    {
        $this->assertHasNoStudio($owner);

        $studio = Studio::create([
            'name' => $data['name'],
            'slug' => $this->generateSlug($data['slug'] ?? $data['name']),
            'email' => $data['email'] ?? null,
            'about' => $data['about'] ?? null,
            'phone' => $data['phone'] ?? null,
            'location' => $data['location'] ?? null,
            'location_lat_long' => $data['location_lat_long'] ?? null,
            'owner_id' => $owner->id,
            'image_id' => $data['image_id'] ?? null,
            'is_claimed' => true,
        ]);

        $this->alignOwnerType($owner);

        return $studio;
    }

    /**
     * Hand an unclaimed studio to its owner.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws StudioOwnerConflictException
     */
    public function claimFor(Studio $studio, User $owner, array $data): Studio
    {
        $this->assertHasNoStudio($owner, $studio->id);

        $updateData = [
            'owner_id' => $owner->id,
            'name' => ($data['name'] ?? null) ?: $studio->name,
            'slug' => ($data['slug'] ?? null) ?: $studio->slug,
            'about' => ($data['about'] ?? null) ?: $studio->about,
            'location' => ($data['location'] ?? null) ?: $studio->location,
            'location_lat_long' => ($data['location_lat_long'] ?? null) ?: $studio->location_lat_long,
            'email' => ($data['email'] ?? null) ?: $studio->email,
            'phone' => ($data['phone'] ?? null) ?: $studio->phone,
            'is_claimed' => true,
        ];

        if (! empty($data['image_id'])) {
            $updateData['image_id'] = $data['image_id'];
        }

        $studio->update($updateData);

        $this->alignOwnerType($owner);

        return $studio;
    }

    /**
     * Give a studio owner the studio type.
     *
     * A studio account represents the business, so the owner's own row carries
     * type 3 and is picked up by the artists index, which holds studio
     * accounts alongside artists.
     *
     * An artist who owns a studio keeps type 2. Relabelling them would take
     * their portfolio out of the artist side of search, which is destructive
     * to an account that is already doing the right thing.
     */
    public function alignOwnerType(User $owner): void
    {
        if ($owner->type_id !== UserTypes::CLIENT_TYPE_ID) {
            return;
        }

        $owner->type_id = UserTypes::STUDIO_TYPE_ID;
        $owner->save();
    }

    /**
     * A studio account is the business, so an owner holds exactly one.
     * `studios.owner_id` is unique, and this turns the collision into a
     * message a signup form can show rather than a database error.
     *
     * @throws StudioOwnerConflictException
     */
    public function assertHasNoStudio(User $owner, ?int $ignoreStudioId = null): void
    {
        $exists = Studio::where('owner_id', $owner->id)
            ->when($ignoreStudioId, fn ($query) => $query->where('id', '!=', $ignoreStudioId))
            ->exists();

        if ($exists) {
            throw new StudioOwnerConflictException('This account already has a studio.');
        }
    }

    /**
     * Put a studio out of public view pending proof that the registrant runs
     * the business.
     *
     * Nothing is deleted. The account, the studio row, its images and the
     * owner's login are all untouched, and liftHold puts every one of them
     * back exactly as they were.
     *
     * @throws StudioAlreadyOnHoldException
     */
    public function placeOnHold(Studio $studio, User $admin, string $reason): Studio
    {
        if ($studio->isOnHold()) {
            throw new StudioAlreadyOnHoldException('This studio is already on hold.');
        }

        $studio->forceFill([
            'hold_status' => StudioHoldStatus::OnHold,
            'hold_reason' => $reason,
            'held_at' => now(),
            'held_by_id' => $admin->id,
            // These describe the hold being placed now, not the previous one.
            'hold_lifted_at' => null,
            'hold_lifted_by_id' => null,
        ])->save();

        $this->syncHoldSearchability($studio);
        $this->requestOwnershipProof($studio);

        return $studio->refresh();
    }

    /**
     * Put a held studio back.
     *
     * held_at, held_by_id and hold_reason are deliberately left in place: they
     * are the record of the hold that was placed, and clearing them would make
     * the action unauditable after the fact.
     *
     * @throws StudioNotOnHoldException
     */
    public function liftHold(Studio $studio, User $admin): Studio
    {
        if (! $studio->isOnHold()) {
            throw new StudioNotOnHoldException('This studio is not on hold.');
        }

        $studio->forceFill([
            'hold_status' => StudioHoldStatus::Active,
            'hold_lifted_at' => now(),
            'hold_lifted_by_id' => $admin->id,
        ])->save();

        $this->syncHoldSearchability($studio);

        return $studio->refresh();
    }

    /**
     * Bring both search documents into line with the studio's hold state.
     *
     * Two documents describe a studio. The studios index holds the studio
     * itself; the artists index holds the owner's account, which carries the
     * studio's name and is the one search actually reads. Both have to move or
     * the page stays findable.
     *
     * Scout's observer would reach the same answer on save, but only when the
     * model was searchable beforehand and only through the configured queue.
     * Doing it here makes the hold take effect on the request that placed it.
     */
    private function syncHoldSearchability(Studio $studio): void
    {
        $studio->isOnHold()
            ? $studio->unsearchable()
            : $studio->searchable();

        if (! $studio->owner_id) {
            return;
        }

        // Artist pins itself to type 2 through a global scope, and a studio
        // account is type 3, so the scope has to come off to find the owner.
        $owner = Artist::withoutGlobalScope(ArtistScope::class)->find($studio->owner_id);

        if (! $owner) {
            return;
        }

        // The relation would otherwise be loaded fresh and miss the change
        // made a moment ago on this instance.
        $owner->setRelation('ownedStudio', $studio);

        $owner->shouldBeSearchable()
            ? $owner->searchable()
            : $owner->unsearchable();
    }

    /**
     * Ask the owner to establish that they run the business.
     *
     * Sent to the owner's registered account address. studios.email is
     * frequently null - it is on both of the records that prompted this - so
     * the studio's own column is not a usable contact route.
     *
     * The hold is already committed by the time this runs. A mail failure must
     * not undo it, and an unclaimed studio has nobody to write to.
     */
    private function requestOwnershipProof(Studio $studio): bool
    {
        $owner = $studio->owner;

        if (! $owner || ! $owner->email) {
            return false;
        }

        try {
            $owner->notify(new StudioVerificationRequestNotification($studio));

            return true;
        } catch (\Throwable $e) {
            Log::error('Failed to send studio verification request', [
                'studio_id' => $studio->id,
                'owner_id' => $owner->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     *
     */
    public function get()
    {
        return Studio::with([
            'image',
            'owner.image',
            'availability',
        ])->paginate(25);
    }


    /**
     * @throws StudioNotFoundException
     */
    public function setStudioImage(string $studio_id, Image $image): Studio
    {
        $studio = $this->getById($studio_id);

        if ($studio) {
            $studio->image_id = $image->id;
            $studio->save();
        } else {
            throw new StudioNotFoundException();
        }

        return $studio;
    }

    /**
     * @throws StudioNotFoundException
     */
    public function setStudioBanner(string $studio_id, Image $image): Studio
    {
        $studio = $this->getById($studio_id);

        if (! $studio) {
            throw new StudioNotFoundException();
        }

        $studio->banner_image_id = $image->id;
        $studio->save();

        return $studio;
    }

    /**
     * Apply a whole studio page edit in one transaction.
     *
     * The editor holds every change until Publish, so this lands details,
     * hours, announcements and spotlights together. Applying them as separate
     * requests meant a failure part way through left the page half-edited.
     *
     * Announcements and spotlights are reconciled against the lists the editor
     * sends: anything missing from them is removed.
     *
     * @param  array<string, mixed>  $data
     */
    public function publishPage(Studio $studio, array $data): Studio
    {
        return DB::transaction(function () use ($studio, $data) {
            $details = array_filter(
                $data,
                fn ($key) => in_array($key, [
                    'name', 'about', 'template', 'section_order', 'section_widths', 'section_columns', 'section_bands', 'section_rows',
                    'phone', 'email', 'website',
                    'address', 'address2', 'city', 'state', 'postal_code',
                ], true),
                ARRAY_FILTER_USE_KEY
            );

            if ($details !== []) {
                $this->applyPageDetails($studio, $details);
            }

            if (array_key_exists('working_hours', $data) && is_array($data['working_hours'])) {
                $this->replaceAvailability($studio, $data['working_hours']);
            }

            if (array_key_exists('announcements', $data) && is_array($data['announcements'])) {
                $this->reconcileAnnouncements($studio, $data['announcements']);
            }

            if (array_key_exists('guides', $data) && is_array($data['guides'])) {
                $this->reconcileGuides($studio, $data['guides']);
            }

            if (array_key_exists('spotlights', $data) && is_array($data['spotlights'])) {
                $this->reconcileSpotlights($studio, $data['spotlights']);
            }

            return $studio->fresh();
        });
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function applyPageDetails(Studio $studio, array $details): void
    {
        $addressKeys = ['address', 'address2', 'city', 'state', 'postal_code'];
        $addressData = array_intersect_key($details, array_flip($addressKeys));

        if ($addressData !== []) {
            $payload = [
                'address1' => $addressData['address'] ?? '',
                'address2' => $addressData['address2'] ?? null,
                'city' => $addressData['city'] ?? '',
                'state' => $addressData['state'] ?? '',
                'postal_code' => $addressData['postal_code'] ?? '',
            ];

            if ($studio->address_id && $studio->address) {
                $studio->address->update($payload);
            } else {
                $studio->address_id = Address::create($payload + ['country_code' => 'US'])->id;
            }
        }

        foreach (array_diff_key($details, array_flip($addressKeys)) as $field => $value) {
            if (in_array($field, $studio->getFillable(), true)) {
                $studio->{$field} = $value;
            }
        }

        $studio->save();
    }

    /**
     * @param  list<array<string, mixed>>  $hours
     */
    private function replaceAvailability(Studio $studio, array $hours): void
    {
        foreach ($hours as $slot) {
            if (! isset($slot['day_of_week'])) {
                continue;
            }

            StudioAvailability::updateOrCreate(
                ['studio_id' => $studio->id, 'day_of_week' => $slot['day_of_week']],
                [
                    'start_time' => $slot['start_time'] ?? '00:00:00',
                    'end_time' => $slot['end_time'] ?? '00:00:00',
                    'is_day_off' => (bool) ($slot['is_day_off'] ?? false),
                ]
            );
        }
    }

    /**
     * @param  list<array<string, mixed>>  $announcements
     */
    private function reconcileAnnouncements(Studio $studio, array $announcements): void
    {
        $keptIds = array_filter(array_column($announcements, 'id'));

        $studio->announcements()
            ->when($keptIds !== [], fn ($query) => $query->whereNotIn('id', $keptIds))
            ->delete();

        foreach ($announcements as $announcement) {
            if (empty($announcement['id'])) {
                $this->createAnnouncementPost($studio, $announcement);

                continue;
            }

            $studio->posts()->where('id', $announcement['id'])->update([
                'type' => $announcement['type'] ?? StudioPostType::General->value,
                'title' => $announcement['title'],
                'content' => $announcement['content'],
                'starts_at' => $announcement['starts_at'] ?? null,
                'ends_at' => $announcement['ends_at'] ?? null,
            ]);
        }
    }

    /**
     * Guides are reconciled the same way announcements are: the editor sends
     * the list it wants and anything missing is removed.
     *
     * Only one aftercare guide can be the default, since only one is sent
     * after an appointment.
     *
     * @param  list<array<string, mixed>>  $guides
     */
    private function reconcileGuides(Studio $studio, array $guides): void
    {
        $keptIds = array_filter(array_column($guides, 'id'));

        $studio->posts()
            ->guides()
            ->when($keptIds !== [], fn ($query) => $query->whereNotIn('id', $keptIds))
            ->delete();

        $defaultAssigned = false;

        foreach ($guides as $guide) {
            $type = $guide['type'] ?? StudioPostType::Aftercare->value;

            // Only an aftercare guide is sent after an appointment. Any other
            // kind carrying the flag would store it inertly and, worse, use up
            // the slot an aftercare guide later in the list should have had.
            $isDefault = $type === StudioPostType::Aftercare->value
                && ! $defaultAssigned
                && (bool) ($guide['is_default'] ?? false);

            $defaultAssigned = $defaultAssigned || $isDefault;

            $attributes = [
                'type' => $type,
                'title' => $guide['title'],
                'excerpt' => $guide['excerpt'] ?? null,
                'content' => $guide['content'],
                'is_default' => $isDefault,
            ];

            if (empty($guide['id'])) {
                $studio->posts()->create($attributes + [
                    'slug' => $this->generatePostSlug($studio, $guide['title']),
                    'status' => StudioPostStatus::Published,
                    'published_at' => now(),
                    'is_active' => true,
                ]);

                continue;
            }

            $studio->posts()->where('id', $guide['id'])->update($attributes);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $spotlights
     */
    private function reconcileSpotlights(Studio $studio, array $spotlights): void
    {
        $wanted = collect($spotlights)
            ->map(fn ($item) => [
                'spotlightable_type' => $item['type'],
                'spotlightable_id' => (int) $item['item_id'],
            ]);

        $studio->spotlights()
            ->get()
            ->each(function (StudioSpotlight $existing) use ($wanted) {
                $stillWanted = $wanted->contains(
                    fn ($item) => $item['spotlightable_type'] === $existing->spotlightable_type
                        && $item['spotlightable_id'] === (int) $existing->spotlightable_id
                );

                if (! $stillWanted) {
                    $existing->delete();
                }
            });

        $wanted->values()->each(function ($item, $index) use ($studio) {
            $studio->spotlights()->updateOrCreate($item, ['display_order' => $index]);
        });
    }

    public function updateStyles(?Studio $studio, $stylesArray): void
    {
        $studio->styles()->sync($stylesArray);
    }

    public function updateTattoos(?Studio $studio, mixed $tattooArray): void
    {
        //
    }

    public function updateArtists(?Studio $studio, mixed $fieldVal): void
    {
        if ($studio && is_array($fieldVal)) {
            $studio->artists()->sync($fieldVal);
        }
    }

    public function addArtistByUsernameOrEmail(Studio $studio, string $identifier, string $initiatedBy = 'studio'): ?User
    {
        // Search by username or email
        $user = User::where('type_id', 2) // Artist type
            ->where(function ($query) use ($identifier) {
                $query->where('username', $identifier)
                      ->orWhere('email', $identifier);
            })
            ->first();

        if ($user) {
            // Add with is_verified = false (pending verification)
            // initiated_by tracks who initiated: 'studio' = invitation, 'artist' = request
            $studio->artists()->syncWithoutDetaching([
                $user->id => [
                    'is_verified' => false,
                    'initiated_by' => $initiatedBy,
                ]
            ]);
            return $user;
        }

        return null;
    }

    public function removeArtist(Studio $studio, int $userId): bool
    {
        return $studio->artists()->detach($userId) > 0;
    }

    public function getStudioArtists(Studio $studio)
    {
        return $studio->artists()->with(['image', 'styles'])->get();
    }

    /**
     * Build a slug for a post, unique within its studio.
     *
     * Announcement titles repeat across years - "Books open" every season - so
     * uniqueness is scoped to the studio and suffixed on collision.
     */
    public function generatePostSlug(Studio $studio, string $title, ?int $ignorePostId = null): string
    {
        $base = Str::slug($title) ?: 'post';
        $slug = $base;
        $suffix = 2;

        while (
            $studio->posts()
                ->where('slug', $slug)
                ->when($ignorePostId, fn ($query) => $query->where('id', '!=', $ignorePostId))
                ->exists()
        ) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }

    /**
     * Create an announcement with the publishing envelope filled in.
     *
     * @param  array<string, mixed>  $data
     */
    private function createAnnouncementPost(Studio $studio, array $data): StudioPost
    {
        return $studio->posts()->create([
            'type' => $data['type'] ?? StudioPostType::General->value,
            'title' => $data['title'],
            'slug' => $this->generatePostSlug($studio, $data['title']),
            'content' => $data['content'],
            'status' => StudioPostStatus::Published,
            'published_at' => now(),
            'starts_at' => $data['starts_at'] ?? null,
            'ends_at' => $data['ends_at'] ?? null,
            'is_active' => $data['is_active'] ?? true,
        ]);
    }

    public function createAnnouncement(Studio $studio, array $data): StudioPost
    {
        return $this->createAnnouncementPost($studio, $data);
    }

    public function updateAnnouncement(StudioPost $announcement, array $data): StudioPost
    {
        $announcement->update($data);
        return $announcement->fresh();
    }

    public function deleteAnnouncement(StudioPost $announcement): bool
    {
        return $announcement->delete();
    }

    public function addSpotlight(Studio $studio, string $type, int $itemId, int $order = 0): StudioSpotlight
    {
        return $studio->spotlights()->updateOrCreate(
            [
                'spotlightable_type' => $type,
                'spotlightable_id' => $itemId,
            ],
            [
                'display_order' => $order,
            ]
        );
    }

    public function removeSpotlight(StudioSpotlight $spotlight): bool
    {
        return $spotlight->delete();
    }

    /**
     * Spotlights with their pinned artist or tattoo resolved.
     *
     * Targets are loaded in two queries rather than one per spotlight, and a
     * spotlight whose target has since been deleted is dropped so the public
     * page never renders a hole.
     */
    public function getSpotlightsWithData(Studio $studio)
    {
        $spotlights = $studio->spotlights;

        $artists = User::with(['image', 'styles'])
            ->whereIn('id', $spotlights->where('spotlightable_type', SpotlightType::Artist->value)->pluck('spotlightable_id'))
            ->get()
            ->keyBy('id');

        $tattoos = Tattoo::with(['primary_image', 'styles', 'primary_style', 'subject', 'artist'])
            ->whereIn('id', $spotlights->where('spotlightable_type', SpotlightType::Tattoo->value)->pluck('spotlightable_id'))
            ->get()
            ->keyBy('id');

        return $spotlights->map(function ($spotlight) use ($artists, $tattoos) {
            $item = match ($spotlight->spotlightable_type) {
                SpotlightType::Artist->value => $artists->get($spotlight->spotlightable_id),
                SpotlightType::Tattoo->value => $tattoos->get($spotlight->spotlightable_id),
                default => null,
            };

            if (! $item) {
                return null;
            }

            return [
                'id' => $spotlight->id,
                'type' => $spotlight->spotlightable_type,
                'item_id' => $spotlight->spotlightable_id,
                'display_order' => $spotlight->display_order,
                'item' => $item instanceof Tattoo
                    ? new TattooResource($item)
                    : new ArtistDashboardResource($item),
            ];
        })->filter()->values();
    }
}
