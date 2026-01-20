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
        'studio_id',
        'type_id',
        'is_admin',
        'address_id',
        'username',
        'slug',
        'last_login_at',
        'experience_level',
        'is_demo',
        'timezone',
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
    ];

    public function scopeArtist(Builder $query): void
    {
        $query->where(['type_id' => UserTypes::ARTIST_TYPE_ID]);
    }

    public function type()
    {
        return $this->belongsTo(Type::class);
    }

    public function image()
    {
        return $this->belongsTo(Image::class);
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

    public function studio()
    {
        return $this->belongsTo(Studio::class);
    }

    public function ownedStudio()
    {
        return $this->hasOne(Studio::class, 'owner_id');
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
     * Check if user is online (seen in last 5 minutes).
     */
    public function isOnline(): bool
    {
        if (!$this->last_seen_at) {
            return false;
        }

        return $this->last_seen_at->gt(now()->subMinutes(5));
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
        }

        return collect(); // fallback: no appointments
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
}
