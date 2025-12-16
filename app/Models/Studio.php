<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Studio extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'address_id',
        'image_id',
        'about',
        'location',
        'location_lat_long',
        'email',
        'password',
        'phone',
        'owner_id',
    ];

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function address()
    {
        return $this->belongsTo(Address::class);
    }

    public function image()
    {
        return $this->belongsTo(Image::class);
    }

    public function styles()
    {
        return $this->belongsToMany(Style::class, 'studios_styles', 'studio_id', 'style_id');
    }

    //TODO this table doesn't exist yet... unsure if we will want this
    public function tattoos()
    {
        return $this->belongsToMany(Tattoo::class, 'studios_tattoos', 'studio_id', 'tattoo_id');
    }

    public function artists()
    {
        return $this->hasMany(User::class,  'artist_id');
    }

    public function business_hours()
    {
        return $this->hasMany(BusinessHours::class);
    }
}
