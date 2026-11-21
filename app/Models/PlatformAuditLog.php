<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformAuditLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'actor_user_id',
        'action',
        'subject_type',
        'subject_id',
        'spa_id',
        'meta',
        'ip',
        'created_at',
    ];

    protected $casts = [
        'meta'       => 'array',
        'created_at' => 'datetime',
    ];

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function spa()
    {
        return $this->belongsTo(Spa::class, 'spa_id');
    }
}
