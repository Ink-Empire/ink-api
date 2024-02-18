<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TattooImages  extends Model
{
    protected $fillable = [
        'tattoo_id',
        'image_id'
    ];
}
