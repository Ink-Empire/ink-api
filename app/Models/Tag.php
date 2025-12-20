<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Tag extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'name',
        'slug',
    ];

    /**
     * Boot the model.
     */
    protected static function boot()
    {
        parent::boot();

        // Auto-generate slug from name on creation
        static::creating(function ($tag) {
            if (empty($tag->slug)) {
                $tag->slug = Str::slug($tag->name);
            }
            // Ensure name is lowercase
            $tag->name = strtolower($tag->name);
        });

        // Ensure consistency on update
        static::updating(function ($tag) {
            if ($tag->isDirty('name')) {
                $tag->slug = Str::slug($tag->name);
                $tag->name = strtolower($tag->name);
            }
        });
    }

    /**
     * Get the tattoos that have this tag.
     */
    public function tattoos()
    {
        return $this->belongsToMany(Tattoo::class, 'tattoos_tags', 'tag_id', 'tattoo_id');
    }

    /**
     * Scope to search tags by name prefix (for autocomplete).
     */
    public function scopeSearch($query, string $term)
    {
        return $query->where('name', 'like', strtolower($term) . '%')
                     ->orWhere('name', 'like', '%' . strtolower($term) . '%');
    }

    /**
     * Get or create a tag by name.
     */
    public static function findOrCreateByName(string $name): self
    {
        $name = strtolower(trim($name));
        $slug = Str::slug($name);

        return static::firstOrCreate(
            ['slug' => $slug],
            ['name' => $name]
        );
    }

    /**
     * Find a tag by name (case-insensitive).
     */
    public static function findByName(string $name): ?self
    {
        return static::where('name', strtolower(trim($name)))->first();
    }

    /**
     * Find a tag by slug.
     */
    public static function findBySlug(string $slug): ?self
    {
        return static::where('slug', $slug)->first();
    }
}
