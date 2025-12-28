<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class BlockedTerm extends Model
{
    protected $fillable = [
        'term',
        'category',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public static function getActiveTerms(): array
    {
        return Cache::remember('blocked_terms', 3600, function () {
            return static::active()->pluck('term')->toArray();
        });
    }

    public static function clearCache(): void
    {
        Cache::forget('blocked_terms');
    }

    protected static function boot()
    {
        parent::boot();

        static::saved(function () {
            static::clearCache();
        });

        static::deleted(function () {
            static::clearCache();
        });
    }
}
