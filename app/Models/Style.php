<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Style extends Model
{
    use HasFactory;

    protected $touches = ['tattoos', 'artists'];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'id',
        'name',
        'parent_id'
    ];

    /**
     * Tattoos that have this style.
     */
    public function tattoos()
    {
        return $this->belongsToMany(Tattoo::class, 'tattoos_styles', 'style_id', 'tattoo_id');
    }

    /**
     * Artists that specialize in this style.
     */
    public function artists()
    {
        // Use users_styles - artists_styles table has been consolidated
        return $this->belongsToMany(User::class, 'users_styles', 'style_id', 'user_id');
    }

    /**
     * Parent style (for hierarchical categories).
     */
    public function parent()
    {
        return $this->belongsTo(Style::class, 'parent_id');
    }

    /**
     * Child styles.
     */
    public function children()
    {
        return $this->hasMany(Style::class, 'parent_id');
    }
}
