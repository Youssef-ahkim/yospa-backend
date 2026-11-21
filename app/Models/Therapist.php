<?php

namespace App\Models;

use App\Traits\BelongsToSpa;
use Illuminate\Database\Eloquent\Model;

class Therapist extends Model
{
    use BelongsToSpa;

    protected $fillable = [
        'spa_id',
        'user_id',
        'name',
        'phone',
        'email',
        'color',
        'notes',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function spa()
    {
        return $this->belongsTo(Spa::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function reservations()
    {
        return $this->hasMany(Reservation::class);
    }
}
