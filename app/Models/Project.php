<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'code', 'location', 'investor', 'status', 'start_date', 'deadline', 'description', 'fiber_layout', 'fiber_color_standard', 'fiber_reserve_per_tube', 'fiber_schema_locked', 'fiber_schema_locked_at', 'fiber_schema_locked_by', 'fiber_budget_limit_db', 'fiber_schema_layout'];

    protected function casts(): array
    {
        return ['fiber_schema_locked' => 'boolean', 'fiber_schema_locked_at' => 'datetime', 'fiber_budget_limit_db' => 'float', 'fiber_schema_layout' => 'array'];
    }

    public function odfs(): HasMany
    {
        return $this->hasMany(Odf::class);
    }

    public function cabinets(): HasMany
    {
        return $this->hasMany(Cabinet::class);
    }

    public function houses(): HasMany
    {
        return $this->hasMany(House::class);
    }

    public function routes(): HasMany
    {
        return $this->hasMany(NetworkRoute::class);
    }

    public function branches(): HasMany
    {
        return $this->hasMany(NetworkBranch::class);
    }

    public function materials(): HasMany
    {
        return $this->hasMany(Material::class);
    }

    public function appendixItems(): HasMany
    {
        return $this->hasMany(ProjectAppendixItem::class);
    }

    public function gisSegments(): HasMany
    {
        return $this->hasMany(GisSegment::class);
    }

    public function gisRestrictedAreas(): HasMany
    {
        return $this->hasMany(GisRestrictedArea::class);
    }

    public function surveyPoints(): HasMany
    {
        return $this->hasMany(SurveyPoint::class);
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(ProjectSnapshot::class);
    }

    public function fiberSplices(): HasMany
    {
        return $this->hasMany(FiberSplice::class);
    }

    public function fiberSchemaVersions(): HasMany
    {
        return $this->hasMany(FiberSchemaVersion::class);
    }
}
