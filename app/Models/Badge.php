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
        'reward_product_id',
    ];

    /** Lo que la casa invita al obtener la insignia (una vez), o null. */
    public function rewardProduct()
    {
        return $this->belongsTo(Product::class, 'reward_product_id');
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'user_badges')->withPivot('obtained_at');
    }
}