<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Message extends Model
{
    use HasFactory;

    protected $fillable = [
        'appointment_id',
        'sender_id',
        'recipient_id',
        'parent_message_id',
        'content',
        'message_type',
        'read_at',
    ];

    protected $casts = [
        'read_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_id');
    }

    public function parentMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'parent_message_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(Message::class, 'parent_message_id');
    }

    public function allReplies(): HasMany
    {
        return $this->replies()->with('allReplies');
    }

    public function isRead(): bool
    {
        return !is_null($this->read_at);
    }

    public function markAsRead(): bool
    {
        if ($this->isRead()) {
            return true;
        }

        return $this->update(['read_at' => now()]);
    }

    public function isReply(): bool
    {
        return !is_null($this->parent_message_id);
    }

    public function isInitial(): bool
    {
        return $this->message_type === 'initial';
    }

    // Scope to get message threads (root messages with their replies)
    public function scopeThreads($query)
    {
        return $query->whereNull('parent_message_id')
                    ->with(['allReplies.sender', 'allReplies.recipient']);
    }

    // Scope to get messages for a specific appointment
    public function scopeForAppointment($query, $appointmentId)
    {
        return $query->where('appointment_id', $appointmentId);
    }

    // Scope to get unread messages for a user
    public function scopeUnreadFor($query, $userId)
    {
        return $query->where('recipient_id', $userId)
                    ->whereNull('read_at');
    }
}
