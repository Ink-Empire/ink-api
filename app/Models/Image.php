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

    public function setUriAttribute($filename)
    {
        $this->attributes['uri'] = 'https://inked-in-images.s3.amazonaws.com/' . $filename;
    }
}
