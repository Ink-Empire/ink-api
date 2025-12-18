<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StudioAnnouncement extends Model
{
    protected $fillable = [
        'studio_id',
        'title',
        'content',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function studio()
    {
        return $this->belongsTo(Studio::class);
    }
}
