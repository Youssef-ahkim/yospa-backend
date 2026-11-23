<?php

namespace App\Models;

use App\Traits\BelongsToSpa;
use Illuminate\Database\Eloquent\Model;

class Reservation extends Model
{
    use BelongsToSpa;

    protected $fillable = [
        'spa_id',
        'client_id',
        'service_id',
        'therapist_id',
        'start_time',
        'end_time',
        'status',
        'total_price',
        'notes',
    ];

    protected $casts = [
        'start_time' => 'datetime:Y-m-d\TH:i:s',
        'end_time' => 'datetime:Y-m-d\TH:i:s',
        'total_price' => 'decimal:2',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function service()
    {
        return $this->belongsTo(Service::class);
    }

    public function therapist()
    {
        return $this->belongsTo(Therapist::class);
    }
}

