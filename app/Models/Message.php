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
        'conversation_id',
        'appointment_id',
        'sender_id',
        'recipient_id',
        'parent_message_id',
        'content',
        'message_type',
        'type',
        'metadata',
        'read_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'read_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

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

    public function attachments(): HasMany
    {
        return $this->hasMany(MessageAttachment::class);
    }

    public function images()
    {
        return $this->belongsToMany(Image::class, 'message_attachments');
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

    public function isText(): bool
    {
        return $this->type === 'text';
    }

    public function isImage(): bool
    {
        return $this->type === 'image';
    }

    public function isBookingCard(): bool
    {
        return $this->type === 'booking_card';
    }

    public function isDepositRequest(): bool
    {
        return $this->type === 'deposit_request';
    }

    public function isSystem(): bool
    {
        return $this->type === 'system';
    }

    public function isDesignShare(): bool
    {
        return $this->type === 'design_share';
    }

    public function isPriceQuote(): bool
    {
        return $this->type === 'price_quote';
    }

    public function isAppointmentReminder(): bool
    {
        return $this->type === 'appointment_reminder';
    }

    public function isAftercare(): bool
    {
        return $this->type === 'aftercare';
    }

    // Check if message is a system-generated type
    public function isSystemGenerated(): bool
    {
        return in_array($this->type, [
            'system',
            'appointment_reminder',
            'appointment_confirmed',
            'appointment_cancelled',
            'deposit_received',
        ]);
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

    // Scope to get messages for a specific conversation
    public function scopeForConversation($query, $conversationId)
    {
        return $query->where('conversation_id', $conversationId);
    }

    // Scope to get unread messages for a user
    public function scopeUnreadFor($query, $userId)
    {
        return $query->where('recipient_id', $userId)
                    ->whereNull('read_at');
    }
}
