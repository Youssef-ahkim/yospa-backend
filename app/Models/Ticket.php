<?php

namespace App\Models;

use App\Traits\BelongsToSpa;
use Illuminate\Database\Eloquent\Model;

class Ticket extends Model
{
    use BelongsToSpa;

    protected $fillable = [
        'spa_id',
        'created_by_user_id',
        'subject',
        'category',
        'priority',
        'status',
        'description',
        'resolved_at',
    ];

    protected $casts = [
        'resolved_at' => 'datetime',
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function messages()
    {
        return $this->hasMany(TicketMessage::class)->orderBy('created_at', 'asc')->orderBy('id', 'asc');
    }
}
