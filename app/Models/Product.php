<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'price',
        'available',
        'image',
        'category_id',
        'day_of_week',
    ];

    protected $casts = [
        'available' => 'boolean',
        'day_of_week' => 'integer',
    ];

    /**
     * Lo que se puede pedir hoy: los productos de todos los días más el menú del
     * día que corresponde (1 = lunes … 6 = sábado; el domingo la pensión no atiende).
     */
    public function scopeDisponiblesHoy($query)
    {
        return $query->where('available', true)
            ->where(fn ($q) => $q->whereNull('day_of_week')->orWhere('day_of_week', now()->dayOfWeekIso));
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function orderItems()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function recommendations()
    {
        return $this->hasMany(Recommendation::class);
    }
}