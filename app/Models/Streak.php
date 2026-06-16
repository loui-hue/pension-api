<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Streak extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'current_streak',
        'longest_streak',
        'last_visit',
    ];

    protected $casts = [
        'last_visit' => 'date',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}