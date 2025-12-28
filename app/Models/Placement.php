<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class Placement extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($placement) {
            if (empty($placement->slug)) {
                $placement->slug = Str::slug($placement->name);
            }
        });

        static::saved(function () {
            static::clearCache();
        });

        static::deleted(function () {
            static::clearCache();
        });
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    public static function getActivePlacements(): \Illuminate\Database\Eloquent\Collection
    {
        return Cache::remember('placements_active', 3600, function () {
            return static::active()->ordered()->get();
        });
    }

    public static function clearCache(): void
    {
        Cache::forget('placements_active');
    }
}
