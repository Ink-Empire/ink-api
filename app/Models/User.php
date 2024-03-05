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

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'about',
        'email',
        'image_id',
        'location',
        'name',
        'password',
        'phone',
        'studio_id',
        'type_id',
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
        $query->where(['type_id' => UserTypes::ARTIST_TYPE]);
    }

    public function type()
    {
        return $this->hasOne(Type::class);
    }

    public function image()
    {
        return $this->belongsTo(Image::class);
    }

    public function studio()
    {
        return $this->belongsTo(Studio::class);
    }

    public function styles()
    {
        return $this->hasMany(Style::class);
    }

    public function tattoos()
    {
        return $this->hasMany(Tattoo::class);
    }
}
