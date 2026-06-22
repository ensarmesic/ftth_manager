<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Cabinet extends Model
{
    protected $fillable = ['project_id', 'odf_id', 'parent_cabinet_id', 'branch_id', 'branch_order', 'name', 'address', 'splitter_count', 'ports_per_splitter', 'latitude', 'longitude'];

    public function project(): BelongsTo { return $this->belongsTo(Project::class); }
    public function odf(): BelongsTo { return $this->belongsTo(Odf::class); }
    public function parentCabinet(): BelongsTo { return $this->belongsTo(Cabinet::class, 'parent_cabinet_id'); }
    public function branch(): BelongsTo { return $this->belongsTo(NetworkBranch::class, 'branch_id'); }
    public function childCabinets(): HasMany { return $this->hasMany(Cabinet::class, 'parent_cabinet_id'); }
    public function houses(): HasMany { return $this->hasMany(House::class); }

    public function getCapacityAttribute(): int
    {
        return (int) $this->splitter_count * (int) $this->ports_per_splitter;
    }

    public function getUsedPortsAttribute(): int
    {
        return $this->houses_count ?? $this->houses()->count();
    }

    public function getUtilizationAttribute(): float
    {
        return $this->capacity > 0 ? round(($this->used_ports / $this->capacity) * 100, 1) : 0;
    }
}
