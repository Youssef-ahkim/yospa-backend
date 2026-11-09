<?php

namespace App\Models;

use App\Traits\BelongsToSpa;
use Illuminate\Database\Eloquent\Model;

class Client extends Model
{
    use BelongsToSpa;

    protected $fillable = [
        'spa_id',
        'full_name',
        'phone',
        'email',
        'notes',
    ];

    public function reservations()
    {
        return $this->hasMany(Reservation::class);
    }
}

