<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserTag extends Model
{
    protected $fillable = [
        'client_id',
        'tag_category_id',
        'label',
    ];

    public function category()
    {
        return $this->belongsTo(UserTagCategory::class, 'tag_category_id');
    }
}
