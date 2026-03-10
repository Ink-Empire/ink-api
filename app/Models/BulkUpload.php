<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class BulkUpload extends Model
{
    protected $fillable = [
        'artist_id',
        'source',
        'status',
        'total_images',
        'cataloged_images',
        'processed_images',
        'published_images',
        'zip_filename',
        'zip_size_bytes',
        'zip_expires_at',
        'original_filename',
        'error_message',
        'completed_at',
    ];

    protected $casts = [
        'zip_expires_at' => 'datetime',
        'completed_at' => 'datetime',
        'total_images' => 'integer',
        'cataloged_images' => 'integer',
        'processed_images' => 'integer',
        'published_images' => 'integer',
        'zip_size_bytes' => 'integer',
    ];

    public function artist(): BelongsTo
    {
        return $this->belongsTo(User::class, 'artist_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(BulkUploadItem::class);
    }

    public function unprocessedItems(): HasMany
    {
        return $this->hasMany(BulkUploadItem::class)
            ->where('is_processed', false)
            ->where('is_skipped', false);
    }

    public function processedItems(): HasMany
    {
        return $this->hasMany(BulkUploadItem::class)
            ->where('is_processed', true);
    }

    public function readyItems(): HasMany
    {
        return $this->hasMany(BulkUploadItem::class)
            ->where('is_processed', true)
            ->where('is_published', false)
            ->where('is_skipped', false)
            ->whereNotNull('primary_style_id')
            ->whereNotNull('placement_id');
    }

    public function unpublishedItems(): HasMany
    {
        return $this->hasMany(BulkUploadItem::class)
            ->where('is_processed', true)
            ->where('is_published', false)
            ->where('is_skipped', false);
    }

    public function publishedItems(): HasMany
    {
        return $this->hasMany(BulkUploadItem::class)
            ->where('is_published', true);
    }

    public function getZipPathAttribute(): ?string
    {
        if (!$this->zip_filename) {
            return null;
        }
        return "bulk-uploads/{$this->artist_id}/{$this->zip_filename}";
    }

    public function getZipUrlAttribute(): ?string
    {
        if (!$this->zip_path) {
            return null;
        }
        return Storage::disk('s3')->url($this->zip_path);
    }

    public function isExpired(): bool
    {
        return $this->zip_expires_at && $this->zip_expires_at->isPast();
    }

    public function isCataloged(): bool
    {
        return in_array($this->status, ['cataloged', 'processing', 'ready', 'completed']);
    }

    public function canProcess(): bool
    {
        return in_array($this->status, ['cataloged', 'processing', 'ready', 'incomplete']) && !$this->isExpired();
    }

    public function canPublish(): bool
    {
        return in_array($this->status, ['ready', 'processing', 'incomplete']) && $this->readyItems()->exists();
    }

    public function updateCounts(): void
    {
        $this->update([
            'cataloged_images' => $this->items()->count(),
            'processed_images' => $this->processedItems()->count(),
            'published_images' => $this->publishedItems()->count(),
        ]);
    }

    public function markFailed(string $errorMessage): void
    {
        $this->update([
            'status' => 'failed',
            'error_message' => $errorMessage,
        ]);
    }

    public function deleteZipFile(): void
    {
        if ($this->zip_path && Storage::disk('s3')->exists($this->zip_path)) {
            Storage::disk('s3')->delete($this->zip_path);
        }
        $this->update(['zip_filename' => null]);
    }
}
