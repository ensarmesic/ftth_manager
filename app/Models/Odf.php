<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Odf extends Model
{
    protected $fillable = ['project_id', 'name', 'address', 'fiber_capacity', 'latitude', 'longitude'];

    public function project(): BelongsTo { return $this->belongsTo(Project::class); }
    public function cabinets(): HasMany { return $this->hasMany(Cabinet::class); }
}
