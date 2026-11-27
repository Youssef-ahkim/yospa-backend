<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class ReservationProduct extends Pivot
{
    protected $table = 'reservation_products';

    protected $casts = [
        'unit_price' => 'decimal:2',
        'quantity'   => 'integer',
    ];
}
