<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BusinessHours extends Model
{
    use HasFactory;

    public $appends = ['day'];
    public $without = ['business_days'];

    protected $fillable = [
        'day_id',
        'open_time',
        'close_time',
        'studio_id'
    ];

    public function getDayAttribute()
    {
        return $this->business_days->day;
    }

    public function business_days()
    {
        return $this->belongsTo(BusinessDay::class, 'day_id', 'id');
    }

    public function studio()
    {
        return $this->belongsTo(Studio::class);
    }
}
