<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Challenge extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'xp_reward',
        'type',
        'start_date',
        'end_date',
        'active',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'active' => 'boolean',
    ];

    public function users()
    {
        return $this->belongsToMany(User::class, 'user_challenges')->withPivot('status', 'progress', 'completed_at');
    }
}