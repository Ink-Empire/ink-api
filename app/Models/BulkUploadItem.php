<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Collection;

class BulkUploadItem extends Model
{
    protected $fillable = [
        'bulk_upload_id',
        'post_group_id',
        'is_primary_in_group',
        'is_cataloged',
        'is_processed',
        'is_published',
        'is_skipped',
        'is_edited',
        'image_id',
        'tattoo_id',
        'zip_path',
        'file_size_bytes',
        'original_caption',
        'original_timestamp',
        'title',
        'description',
        'placement_id',
        'primary_style_id',
        'additional_style_ids',
        'ai_suggested_tags',
        'approved_tag_ids',
        'sort_order',
    ];

    protected $casts = [
        'is_primary_in_group' => 'boolean',
        'is_cataloged' => 'boolean',
        'is_processed' => 'boolean',
        'is_published' => 'boolean',
        'is_skipped' => 'boolean',
        'is_edited' => 'boolean',
        'original_timestamp' => 'datetime',
        'additional_style_ids' => 'array',
        'ai_suggested_tags' => 'array',
        'approved_tag_ids' => 'array',
        'file_size_bytes' => 'integer',
        'sort_order' => 'integer',
    ];

    public function bulkUpload(): BelongsTo
    {
        return $this->belongsTo(BulkUpload::class);
    }

    public function image(): BelongsTo
    {
        return $this->belongsTo(Image::class);
    }

    public function tattoo(): BelongsTo
    {
        return $this->belongsTo(Tattoo::class);
    }

    public function placement(): BelongsTo
    {
        return $this->belongsTo(Placement::class);
    }

    public function primaryStyle(): BelongsTo
    {
        return $this->belongsTo(Style::class, 'primary_style_id');
    }

    public function isPartOfGroup(): bool
    {
        return $this->post_group_id !== null;
    }

    public function getGroupItems(): Collection
    {
        if (!$this->isPartOfGroup()) {
            return new Collection([$this]);
        }

        return self::where('bulk_upload_id', $this->bulk_upload_id)
            ->where('post_group_id', $this->post_group_id)
            ->orderBy('is_primary_in_group', 'desc')
            ->orderBy('sort_order')
            ->get();
    }

    public function getGroupCount(): int
    {
        if (!$this->isPartOfGroup()) {
            return 1;
        }

        return self::where('bulk_upload_id', $this->bulk_upload_id)
            ->where('post_group_id', $this->post_group_id)
            ->count();
    }

    public function isReadyForPublish(): bool
    {
        return $this->is_processed
            && !$this->is_published
            && !$this->is_skipped
            && $this->primary_style_id !== null
            && $this->placement_id !== null;
    }

    public function getFilenameFromPath(): string
    {
        return basename($this->zip_path);
    }

    public function getThumbnailUrlAttribute(): ?string
    {
        return $this->image?->uri;
    }

    public function getAllTagIds(): array
    {
        return $this->approved_tag_ids ?? [];
    }

    public function getAllStyleIds(): array
    {
        $styles = [];
        if ($this->primary_style_id) {
            $styles[] = $this->primary_style_id;
        }
        if ($this->additional_style_ids) {
            $styles = array_merge($styles, $this->additional_style_ids);
        }
        return array_unique($styles);
    }

    public function scopeUnprocessed($query)
    {
        return $query->where('is_processed', false)->where('is_skipped', false);
    }

    public function scopeProcessed($query)
    {
        return $query->where('is_processed', true);
    }

    public function scopeReadyForPublish($query)
    {
        return $query->where('is_processed', true)
            ->where('is_published', false)
            ->where('is_skipped', false)
            ->whereNotNull('primary_style_id')
            ->whereNotNull('placement_id');
    }

    public function scopePrimaryInGroup($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('post_group_id')
              ->orWhere('is_primary_in_group', true);
        });
    }
}
