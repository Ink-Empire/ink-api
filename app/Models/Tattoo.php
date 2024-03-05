<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Tattoo extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'description',
        'placement',
        'artist_id',
        'studio_id',
        'primary_style_id',
        'primary_subject_id',
        'primary_image_id',
    ];

    public function artist()
    {
        return $this->hasOne(User::class);
    }

    public function studio()
    {
        return $this->belongsTo(Studio::class);
    }

    //todo we need all secondary tags
    public function style()
    {
        return $this->belongsTo(Style::class);
    }

    public function theme()
    {
        return $this->belongsTo(Subject::class);
    }

    public function image()
    {
        return $this->belongsTo(Image::class);
    }
}
