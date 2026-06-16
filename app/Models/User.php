<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'avatar',
        'password',
        'status',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
    ];

    public function roles()
    {
        return $this->belongsToMany(Role::class, 'role_user');
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    public function loyaltyPoints()
    {
        return $this->hasMany(LoyaltyPoint::class);
    }

    public function streak()
    {
        return $this->hasOne(Streak::class);
    }

    public function badges()
    {
        return $this->belongsToMany(Badge::class, 'user_badges')->withPivot('obtained_at');
    }

    public function challenges()
    {
        return $this->belongsToMany(Challenge::class, 'user_challenges')->withPivot('status', 'progress', 'completed_at');
    }

    public function recommendations()
    {
        return $this->hasMany(Recommendation::class);
    }
}