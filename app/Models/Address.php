<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Address extends Model
{
    use HasFactory;

    protected $fillable = [
        'first_name',
        'last_name',
        'address1',
        'address2',
        'city',
        'state',
        'postal_code',
        'country_code',
        'phone',
        'is_active',
    ];
}
