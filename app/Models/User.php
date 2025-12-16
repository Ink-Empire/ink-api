<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\UserTypes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\Builder;


class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $with = ['ownedStudio'];

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
        'address_id',
        'username',
        'slug'
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

    public function appointmentsWithStatus($status)
    {
        if ($this->type_id === UserTypes::CLIENT_TYPE_ID) {
            return Appointment::forClientWithStatus($this->id, $status);
        } elseif ($this->type_id === UserTypes::ARTIST_TYPE_ID) {
            return Appointment::forArtistWithStatus($this->id, $status);
        }

        return collect(); // fallback: no appointments
    }
}
