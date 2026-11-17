<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserChallenge extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'challenge_id',
        'status',
        'progress',
        'current_count',
        'last_counted_on',
        'completed_at',
    ];

    protected $casts = [
        'completed_at' => 'datetime',
        'last_counted_on' => 'date',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function challenge()
    {
        return $this->belongsTo(Challenge::class);
    }
}