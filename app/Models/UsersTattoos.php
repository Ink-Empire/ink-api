<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UsersTattoos  extends Model
{
    protected $fillable = [
        'user_id',
        'tattoo_id',
    ];
}
