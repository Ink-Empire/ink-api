<?php

namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class Appointment extends Model
{
    protected static function booted()
    {
        static::saved(function ($appointment) {
            // Your logic here, e.g.:
            //TODO if the appointment status is changed to booked, send a notification to the client
        });
    }

    protected $fillable = [
        'id',
        'title',
        'description',
        'client_id',
        'artist_id',
        'studio_id',
        'tattoo_id',
        'date',
        'status', // booked, completed, cancelled
        'google_event_id',
        'type', // tattoo or consultation
        'all_day',
        'start_time',
        'end_time',
    ];

    protected $casts = [
        'date' => 'date',
        'all_day' => 'boolean',
    ];

    public function client()
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    public function artist()
    {
        return $this->belongsTo(User::class, 'artist_id');
    }

    public function studio()
    {
        return $this->belongsTo(Studio::class);
    }

    public function tattoo()
    {
        return $this->belongsTo(Tattoo::class);
    }

    public function messages()
    {
        return $this->hasMany(Message::class);
    }

    public function messageThreads()
    {
        return $this->hasMany(Message::class)->threads();
    }

    public function latestMessage()
    {
        return $this->hasOne(Message::class)->latest();
    }

    public function unreadMessagesFor($userId)
    {
        return $this->messages()->unreadFor($userId);
    }

    public function hasUnreadMessagesFor($userId)
    {
        return $this->unreadMessagesFor($userId)->exists();
    }

    public function scopeForArtistWithStatus($query, $artistId, array $status)
    {
        return $query->where('artist_id', $artistId)
            ->whereIn('status', $status)
            ->with(['client', 'artist']);
    }

    public function scopeForClientWithStatus($query, $clientId, $status)
    {
        return $query->where('client_id', $clientId)
            ->whereIn('status', is_array($status) ? $status : [$status])
            ->with(['client', 'artist']);
    }

    public function scopeWithMessages($query)
    {
        return $query->with(['messages.sender', 'messages.recipient', 'latestMessage']);
    }
}
