<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Odf extends Model
{
    use HasFactory;

    protected $fillable = ['project_id', 'name', 'address', 'fiber_capacity', 'port_count', 'latitude', 'longitude', 'notes'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function cabinets(): HasMany
    {
        return $this->hasMany(Cabinet::class);
    }

    public function branches(): HasMany
    {
        return $this->hasMany(NetworkBranch::class);
    }
}
