<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UsersShops  extends Model
{
    protected $fillable = [
        'user_id',
        'shop_id',
    ];
}
