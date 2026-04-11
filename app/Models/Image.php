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
        'is_primary',
        'edit_params',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
        'edit_params' => 'array',
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

    /**
     * Normalize legacy CloudFront/S3 URIs to imgix on read.
     */
    public function getUriAttribute($value)
    {
        if (!$value || !config('filesystems.imgix.enabled')) {
            return $value;
        }

        $imgixUrl = rtrim(config('filesystems.imgix.url'), '/');

        // Already an imgix URL
        if (str_contains($value, 'imgix.net')) {
            return $value;
        }

        // Skip non-image URLs (gravatar, etc.)
        if (!str_contains($value, 'cloudfront.net') && !str_contains($value, 's3.amazonaws.com') && !str_contains($value, 's3.us-east-1.amazonaws.com')) {
            return $value;
        }

        // Extract the filename/path after the domain
        $path = preg_replace('#^https?://[^/]+/#', '', $value);

        return $imgixUrl . '/' . $path;
    }

    public function setUriAttribute($filename = null)
    {
        if (!$filename) {
            $this->attributes['uri'] = "https://www.gravatar.com/avatar?d=mm&s=140";
        } else {
            if (config('filesystems.imgix.enabled')) {
                $imgixUrl = rtrim(config('filesystems.imgix.url'), '/');
                $this->attributes['uri'] = $imgixUrl . '/' . $filename;
            } else {
                $s3Url = rtrim(config('filesystems.disks.s3.url', 'https://inked-in-images.s3.amazonaws.com'), '/');
                $this->attributes['uri'] = $s3Url . '/' . $filename;
            }
        }
    }
}
