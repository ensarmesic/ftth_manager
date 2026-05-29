<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    protected $fillable = ['name', 'code', 'location', 'status', 'start_date', 'deadline', 'description'];

    public function odfs(): HasMany { return $this->hasMany(Odf::class); }
    public function cabinets(): HasMany { return $this->hasMany(Cabinet::class); }
    public function subscribers(): HasMany { return $this->hasMany(Subscriber::class); }
    public function houses(): HasMany { return $this->hasMany(House::class); }
    public function routes(): HasMany { return $this->hasMany(NetworkRoute::class); }
    public function materials(): HasMany { return $this->hasMany(Material::class); }
}
