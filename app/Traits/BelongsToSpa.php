<?php

namespace App\Traits;

use App\Models\Spa;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

trait BelongsToSpa
{
    protected static function bootBelongsToSpa()
    {
        // Automatically scope queries by spa_id if user is authenticated and belongs to a spa
        static::addGlobalScope('spa', function (Builder $builder) {
            if (auth()->check() && auth()->user()->spa_id) {
                $builder->where($builder->getModel()->getTable() . '.spa_id', auth()->user()->spa_id);
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

