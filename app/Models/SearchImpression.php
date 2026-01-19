<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SearchImpression extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'impressionable_type',
        'impressionable_id',
        'user_id',
        'search_location',
        'search_coords',
        'search_filters',
        'ip_address',
    ];

    protected $casts = [
        'search_filters' => 'array',
        'created_at' => 'datetime',
    ];

    public function impressionable()
    {
        return $this->morphTo();
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
