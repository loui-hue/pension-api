<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentMethod extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'active',
        'requires_confirmation',
        'qr_image_path',
        'bank_name',
        'account_number',
        'account_holder',
    ];

    protected $casts = [
        'active' => 'boolean',
        'requires_confirmation' => 'boolean',
    ];

    protected $hidden = ['qr_image_path'];

    protected $appends = ['has_qr'];

    public function getHasQrAttribute(): bool
    {
        return !empty($this->qr_image_path);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }
}
