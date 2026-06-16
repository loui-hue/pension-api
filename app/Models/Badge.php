<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Badge extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'image',
        'xp_required',
        'type',
    ];

    public function users()
    {
        return $this->belongsToMany(User::class, 'user_badges')->withPivot('obtained_at');
    }
}