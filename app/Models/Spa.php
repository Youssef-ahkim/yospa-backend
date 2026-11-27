<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Spa extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'status',
    ];

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function clients()
    {
        return $this->hasMany(Client::class);
    }

    public function services()
    {
        return $this->hasMany(Service::class);
    }

    public function reservations()
    {
        return $this->hasMany(Reservation::class);
    }

    public function therapists()
    {
        return $this->hasMany(Therapist::class);
    }

    public function tickets()
    {
        return $this->hasMany(Ticket::class);
    }

    public function products()
    {
        return $this->hasMany(Product::class);
    }
}

