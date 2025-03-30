<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Image extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'filename',
        'uri',
        'is_primary'
    ];

    protected $casts = [
        'is_primary' => 'boolean',
    ];

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
