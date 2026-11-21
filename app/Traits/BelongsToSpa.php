<?php

namespace App\Traits;

use App\Models\Spa;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

trait BelongsToSpa
{
    protected static function bootBelongsToSpa()
    {
        // Automatically scope queries by spa_id if user is authenticated and belongs to a spa.
        // Fails closed (1 = 0) if authenticated user has no spa_id.
        static::addGlobalScope('spa', function (Builder $builder) {
            if (auth()->check()) {
                if (auth()->user()->spa_id) {
                    $builder->where($builder->getModel()->getTable() . '.spa_id', auth()->user()->spa_id);
                } else {
                    $builder->whereRaw('1 = 0');
                }
            }
        });

        // Automatically assign spa_id on creation if missing
        static::creating(function (Model $model) {
            if (auth()->check() && auth()->user()->spa_id && empty($model->spa_id)) {
                $model->spa_id = auth()->user()->spa_id;
            }
        });
    }

    public function spa()
    {
        return $this->belongsTo(Spa::class);
    }
}

