<?php

namespace App\Models;

use App\Traits\BelongsToSpa;
use Illuminate\Database\Eloquent\Model;

class Service extends Model
{
    use BelongsToSpa;

    protected $fillable = [
        'spa_id',
        'name',
        'description',
        'duration_minutes',
        'price',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'price' => 'decimal:2',
        'duration_minutes' => 'integer',
    ];

    public function reservations()
    {
        return $this->hasMany(Reservation::class);
    }
}

