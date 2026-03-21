<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserTagCategory extends Model
{
    public const EXAMPLE_TAGS = [
        'Style preferences' => ['fine line', 'blackwork', 'botanical', 'realism', 'no colour fills'],
        'Avoid' => ['bold outlines', 'colour fills', 'wrist placement', 'large scale'],
        'Personality' => ['chatty', 'nervous', 'needs breaks', 'music off', 'bring a friend'],
        'Pain notes' => ['high tolerance', 'sensitive ribs', 'hates outlining'],
    ];

    protected $fillable = [
        'studio_user_id',
        'name',
        'color',
        'sort_order',
    ];

    public function tags()
    {
        return $this->hasMany(UserTag::class, 'tag_category_id');
    }

    public function getExampleTags(): array
    {
        return self::EXAMPLE_TAGS[$this->name] ?? [];
    }
}
