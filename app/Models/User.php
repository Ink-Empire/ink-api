<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\UserTypes;
use App\Notifications\VerifyEmailNotification;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\Builder;


class User extends Authenticatable implements MustVerifyEmail
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $with = ['ownedStudio', 'image'];

//    protected static function booted()
//    {
//        static::saved(function ($user) {
//            if ($user->type_id === UserTypes::ARTIST_TYPE_ID) {
//                $artist = Artist::find($user->id);
//                $artist->searchable();
//            }
//        });
//    }

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'id',
        'about',
        'email',
        'image_id',
        'location',
        'location_lat_long',
        'name',
        'password',
        'phone',
        'type_id',
        'is_admin',
        'address_id',
        'username',
        'slug',
        'last_login_at',
        'experience_level',
        'is_demo',
        'timezone',
        'is_subscribed',
        'is_email_verified',
        'last_seen_at',
        'email_unsubscribed',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'last_login_at' => 'datetime',
        'is_admin' => 'boolean',
        'is_demo' => 'boolean',
        'is_email_verified' => 'boolean',
        'email_unsubscribed' => 'boolean',
    ];

    /**SCOPES**/

    public function scopeArtist(Builder $query): void
    {
        $query->where(['type_id' => UserTypes::ARTIST_TYPE_ID]);
    }

    public function scopeNotBlockedBy($query, ?User $user = null)
    {
        $user = $user ?? auth()->user();

        if (!$user) {
            return $query;
        }

        return $query->whereNotIn('users.id', function ($q) use ($user) {
            $q->select('blocked_id')
                ->from('user_blocks')
                ->where('blocker_id', $user->id);
        });
    }

    public function type()
    {
        return $this->belongsTo(Type::class);
    }

    public function image()
    {
        return $this->belongsTo(Image::class);
    }

    /**
     * Get the artist settings for this user (only applicable for artists).
     */
    public function settings()
    {
        return $this->hasOne(ArtistSettings::class, 'artist_id', 'id');
    }

    public function styles()
    {
        return $this->belongsToMany(Style::class, 'users_styles', 'user_id', 'style_id');
    }

    public function tattoos()
    {
        return $this->belongsToMany(Tattoo::class, 'users_tattoos', 'user_id', 'tattoo_id');
    }

    public function artists()
    {
        return $this->belongsToMany(User::class, 'users_artists', 'user_id', 'artist_id');
    }

    /**
     * Artists on the user's wishlist (for booking notifications).
     */
    public function wishlistArtists()
    {
        return $this->belongsToMany(User::class, 'artist_wishlists', 'user_id', 'artist_id')
            ->notBlockedBy($this)
            ->withPivot('notify_booking_open', 'notified_at')
            ->withTimestamps();
    }

    /**
     * Wishlist entries for this user.
     */
    public function artistWishlists()
    {
        return $this->hasMany(ArtistWishlist::class, 'user_id');
    }

    public function address()
    {
        return $this->belongsTo(Address::class);
    }

    /**
     * Get the user's primary verified studio.
     * This replaces the old belongsTo relationship with studio_id column.
     */
    public function primaryStudio()
    {
        return $this->belongsToMany(Studio::class, 'users_studios', 'user_id', 'studio_id')
            ->withPivot('is_verified', 'verified_at', 'initiated_by', 'is_primary')
            ->wherePivot('is_verified', true)
            ->wherePivot('is_primary', true)
            ->withTimestamps();
    }

    /**
     * Get the primary studio (single model, not relationship for Elasticsearch compatibility).
     */
    public function getPrimaryStudioAttribute()
    {
        return $this->primaryStudio()->with('image')->first();
    }

    /**
     * Legacy studio() method - returns primary studio for backwards compatibility.
     * @deprecated Use primaryStudio() or verifiedStudios() instead
     */
    public function studio()
    {
        return $this->primaryStudio();
    }

    public function ownedStudio()
    {
        return $this->hasOne(Studio::class, 'owner_id');
    }

    /**
     * Studios this user is affiliated with (via users_studios pivot table).
     */
    public function affiliatedStudios()
    {
        return $this->belongsToMany(Studio::class, 'users_studios', 'user_id', 'studio_id')
            ->withPivot('is_verified', 'verified_at', 'initiated_by', 'is_primary')
            ->withTimestamps();
    }

    /**
     * Verified studio affiliations.
     */
    public function verifiedStudios()
    {
        return $this->belongsToMany(Studio::class, 'users_studios', 'user_id', 'studio_id')
            ->withPivot('is_verified', 'verified_at', 'initiated_by', 'is_primary')
            ->wherePivot('is_verified', true)
            ->withTimestamps();
    }

    /**
     * Pending studio invitations (studio invited this artist, awaiting acceptance).
     */
    public function pendingStudioInvitations()
    {
        return $this->belongsToMany(Studio::class, 'users_studios', 'user_id', 'studio_id')
            ->withPivot('is_verified', 'verified_at', 'initiated_by', 'is_primary')
            ->wherePivot('is_verified', false)
            ->wherePivot('initiated_by', 'studio')
            ->withTimestamps();
    }

    /**
     * Pending studio requests (artist requested to join, awaiting studio approval).
     */
    public function pendingStudioRequests()
    {
        return $this->belongsToMany(Studio::class, 'users_studios', 'user_id', 'studio_id')
            ->withPivot('is_verified', 'verified_at', 'initiated_by', 'is_primary')
            ->wherePivot('is_verified', false)
            ->wherePivot('initiated_by', 'artist')
            ->withTimestamps();
    }

    /**
     * Set a studio as the user's primary studio.
     */
    public function setPrimaryStudio(int $studioId): bool
    {
        // First, verify the user is affiliated with this studio
        $affiliation = $this->verifiedStudios()->where('studios.id', $studioId)->first();
        if (!$affiliation) {
            return false;
        }

        // Remove primary flag from all other studios
        $this->affiliatedStudios()->updateExistingPivot(
            $this->affiliatedStudios()->pluck('studios.id')->toArray(),
            ['is_primary' => false]
        );

        // Set the new primary studio
        $this->affiliatedStudios()->updateExistingPivot($studioId, ['is_primary' => true]);

        return true;
    }

    public function artistSettings()
    {
        return $this->hasOne(ArtistSettings::class, 'artist_id');
    }

    public function tattooLeads()
    {
        return $this->hasMany(TattooLead::class);
    }

    public function activeTattooLead()
    {
        return $this->hasOne(TattooLead::class)->where('is_active', true);
    }

    public function passwords()
    {
        return $this->hasMany(Password::class);
    }

    public function socialMediaLinks()
    {
        return $this->hasMany(SocialMediaLink::class);
    }

    public function sendEmailVerificationNotification()
    {
        $this->notify(new VerifyEmailNotification);
    }

    public function sendPasswordResetNotification($token)
    {
        $this->notify(new ResetPasswordNotification($token));
    }

    /**
     * Get all profile views for this user (as an artist).
     */
    public function profileViews()
    {
        return $this->morphMany(ProfileView::class, 'viewable');
    }

    /**
     * Get all conversations for this user.
     */
    public function conversations()
    {
        return $this->belongsToMany(Conversation::class, 'conversation_participants')
            ->withPivot('last_read_at')
            ->withTimestamps();
    }

    /**
     * Get conversation participants for this user.
     */
    public function conversationParticipants()
    {
        return $this->hasMany(ConversationParticipant::class);
    }

    /**
     * Check if user is online (seen within the configured timeout).
     */
    public function isOnline(): bool
    {
        if (!$this->last_seen_at) {
            return false;
        }

        $timeoutMinutes = config('app.online_timeout_minutes', 5);
        return $this->last_seen_at->gt(now()->subMinutes($timeoutMinutes));
    }

    /**
     * Update the user's last seen timestamp.
     */
    public function updateLastSeen(): bool
    {
        return $this->update(['last_seen_at' => now()]);
    }

    public function appointmentsWithStatus($status)
    {
        if ($this->type_id === UserTypes::CLIENT_TYPE_ID) {
            return Appointment::forClientWithStatus($this->id, $status);
        } elseif ($this->type_id === UserTypes::ARTIST_TYPE_ID) {
            return Appointment::forArtistWithStatus($this->id, $status);
        } elseif ($this->type_id === UserTypes::STUDIO_TYPE_ID) {
            // For studio accounts, get appointments for all artists in the studio
            $studio = $this->owned_studio;
            if ($studio) {
                $artistIds = $studio->artists()->pluck('users.id')->toArray();
                return Appointment::whereIn('artist_id', $artistIds)
                    ->whereIn('status', is_array($status) ? $status : [$status]);
            }
        }

        // Return empty query builder (not collection) so ->with() still works
        return Appointment::whereRaw('1 = 0');
    }

    /**
     * Get the user's Google Calendar connection.
     */
    public function calendarConnection()
    {
        return $this->hasOne(CalendarConnection::class)->where('provider', 'google');
    }

    /**
     * Check if the user has a Google Calendar connected.
     */
    public function hasGoogleCalendarConnected(): bool
    {
        return $this->calendarConnection()->exists();
    }

    /**
     * Users that this user has blocked.
     */
    public function blockedUsers()
    {
        return $this->belongsToMany(User::class, 'user_blocks', 'blocker_id', 'blocked_id')
            ->withPivot('reason')
            ->withTimestamps();
    }

    /**
     * Users who have blocked this user.
     */
    public function blockedByUsers()
    {
        return $this->belongsToMany(User::class, 'user_blocks', 'blocked_id', 'blocker_id')
            ->withPivot('reason')
            ->withTimestamps();
    }

    /**
     * Check if this user has blocked another user.
     */
    public function hasBlocked(int $userId): bool
    {
        return $this->blockedUsers()->where('blocked_id', $userId)->exists();
    }

    /**
     * Check if this user is blocked by another user.
     */
    public function isBlockedBy(int $userId): bool
    {
        return $this->blockedByUsers()->where('blocker_id', $userId)->exists();
    }

    /**
     * Block a user.
     */
    public function block(int $userId, ?string $reason = null): void
    {
        if (!$this->hasBlocked($userId)) {
            $this->blockedUsers()->attach($userId, ['reason' => $reason]);
        }
    }

    /**
     * Unblock a user.
     */
    public function unblock(int $userId): void
    {
        $this->blockedUsers()->detach($userId);
    }

    /**
     * Get all user IDs that are blocked (either direction).
     */
    public function getAllBlockedIds(): array
    {
        $blockedUserIds = $this->blockedUsers()->pluck('blocked_id')->toArray();
        $blockedByUserIds = $this->blockedByUsers()->pluck('blocker_id')->toArray();

        return array_unique(array_merge($blockedUserIds, $blockedByUserIds));
    }

    /**
     * Check if a user ID is in the blocked list (either direction).
     */
    public function isBlocked(int $userId): bool
    {
        return $this->hasBlocked($userId) || $this->isBlockedBy($userId);
    }

    /**
     * Check if user wants to receive marketing/notification emails.
     * Returns false if they have unsubscribed.
     */
    public function wantsMarketingEmails(): bool
    {
        return !$this->email_unsubscribed;
    }
}
