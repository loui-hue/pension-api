<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'product_id',
        'quantity',
        'unit_price',
        'subtotal',
        'notes',
        'courtesy_badge_id',
    ];

    /** Insignia cuya cortesía es este plato (precio 0), o null si se cobra. */
    public function courtesyBadge()
    {
        return $this->belongsTo(Badge::class, 'courtesy_badge_id');
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}