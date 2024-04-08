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
            $this->attributes['uri'] = 'https://inked-in-images.s3.amazonaws.com/' . $filename;
        }
    }
}
