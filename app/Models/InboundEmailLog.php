<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InboundEmailLog extends Model
{
    protected $fillable = [
        'message_uid',
        'sender_email',
        'sender_name',
        'image_count',
        'is_processed',
        'processed_at',
        'error_message',
        'bulk_upload_id',
    ];

    protected $casts = [
        'is_processed' => 'boolean',
        'processed_at' => 'datetime',
    ];

    public function bulkUpload(): BelongsTo
    {
        return $this->belongsTo(BulkUpload::class);
    }
}
