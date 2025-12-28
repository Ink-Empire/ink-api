<?php

namespace App\Models;

use App\Jobs\ReindexTaggedTattoosJob;
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
        'is_pending',
    ];

    protected $casts = [
        'is_pending' => 'boolean',
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

        // Dispatch reindex job when tag is approved
        static::updated(function ($tag) {
            if ($tag->wasChanged('is_pending') && !$tag->is_pending) {
                ReindexTaggedTattoosJob::dispatch($tag->id);
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
     * Scope to only show approved (non-pending) tags.
     */
    public function scopeApproved($query)
    {
        return $query->where('is_pending', false);
    }

    /**
     * Scope to only show pending tags.
     */
    public function scopePending($query)
    {
        return $query->where('is_pending', true);
    }

    /**
     * Scope to search tags by name prefix (for autocomplete).
     * Only searches approved tags by default.
     */
    public function scopeSearch($query, string $term)
    {
        return $query->approved()
                     ->where(function ($q) use ($term) {
                         $q->where('name', 'like', strtolower($term) . '%')
                           ->orWhere('name', 'like', '%' . strtolower($term) . '%');
                     });
    }

    /**
     * Get or create a tag by name.
     * New user-created tags are set as pending until approved by admin.
     */
    public static function findOrCreateByName(string $name): self
    {
        $name = strtolower(trim($name));
        $slug = Str::slug($name);

        // First check if tag already exists (approved or pending)
        $existing = static::where('slug', $slug)->first();
        if ($existing) {
            return $existing;
        }

        // Create new tag as pending (requires admin approval)
        return static::create([
            'name' => $name,
            'slug' => $slug,
            'is_pending' => true,
        ]);
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
