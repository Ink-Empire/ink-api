<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Image extends Model
{
    use HasFactory;

    protected $touches = ['tattoos', 'tattoosAsPrimary', 'artists'];

    protected $fillable = [
        'name',
        'filename',
        'uri',
        'is_primary'
    ];

    protected $casts = [
        'is_primary' => 'boolean',
    ];

    /**
     * Tattoos that use this image in their gallery.
     */
    public function tattoos()
    {
        return $this->belongsToMany(Tattoo::class, 'tattoos_images', 'image_id', 'tattoo_id');
    }

    /**
     * Tattoos that use this as their primary image.
     */
    public function tattoosAsPrimary()
    {
        return $this->hasMany(Tattoo::class, 'primary_image_id');
    }

    /**
     * Artists/Users that use this as their profile image.
     */
    public function artists()
    {
        return $this->hasMany(User::class, 'image_id');
    }

    public function setUriAttribute($filename = null)
    {
        if (!$filename) { //replace with image not found perhaps
            $this->attributes['uri'] = "https://www.gravatar.com/avatar?d=mm&s=140";
        } else {
            // Use configured AWS_URL from env file instead of hardcoded URL
            $s3Url = rtrim(config('filesystems.disks.s3.url', 'https://inked-in-images.s3.amazonaws.com'), '/');
            $this->attributes['uri'] = $s3Url . '/' . $filename;
        }
    }
}
