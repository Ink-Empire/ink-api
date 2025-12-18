<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MessageAttachment extends Model
{
    use HasFactory;

    protected $fillable = [
        'message_id',
        'image_id',
    ];

    public function message()
    {
        return $this->belongsTo(Message::class);
    }

    public function image()
    {
        return $this->belongsTo(Image::class);
    }
}
