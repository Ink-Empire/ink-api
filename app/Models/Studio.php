<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Studio extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'address_id',
        'about',
        'location',
        'email',
        'phone',
    ];

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
        return $this->hasMany(Style::class);
    }

    public function tattoos()
    {
        return $this->hasMany(Tattoo::class);
    }

    public function business_hours()
    {
        return $this->hasMany(BusinessHours::class);
    }
}
