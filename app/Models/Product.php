<?php

namespace App\Models;

use App\Traits\BelongsToSpa;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use BelongsToSpa;

    protected $fillable = [
        'spa_id',
        'name',
        'sku',
        'unit',
        'price',
        'stock_quantity',
        'low_stock_threshold',
        'description',
        'is_active',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'stock_quantity' => 'integer',
        'low_stock_threshold' => 'integer',
        'is_active' => 'boolean',
    ];

    public function spa()
    {
        return $this->belongsTo(Spa::class);
    }

    public function reservations()
    {
        return $this->belongsToMany(Reservation::class, 'reservation_products')
            ->using(ReservationProduct::class)
            ->withPivot(['id', 'quantity', 'unit_price'])
            ->withTimestamps();
    }
}
