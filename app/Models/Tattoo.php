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
        'user_id',
        'shop_id',
        'style_id',
        'theme_id',
        'image_id',
        'tags'
    ];

    public function artist()
    {
        return $this->hasOne(User::class);
    }

    public function shop()
    {
        return $this->belongsTo(Shop::class);
    }

    public function style()
    {
        return $this->belongsTo(Style::class);
    }
    public function theme()
    {
        return $this->belongsTo(Theme::class);
    }

    public function image()
    {
        return $this->belongsTo(Image::class);
    }
}
