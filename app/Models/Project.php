<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'code', 'location', 'investor', 'status', 'start_date', 'deadline', 'description', 'fiber_layout', 'fiber_color_standard', 'fiber_reserve_per_tube', 'fiber_schema_locked', 'fiber_schema_locked_at', 'fiber_schema_locked_by', 'fiber_budget_limit_db', 'fiber_schema_layout', 'pon_profile', 'feeder_splitter_ratio', 'fiber_attenuation_1310_db_km', 'fiber_attenuation_1490_db_km', 'fiber_attenuation_1577_db_km', 'connector_loss_db', 'connector_count', 'splice_allowance_db', 'planned_splice_count', 'engineering_margin_db', 'additional_passive_loss_db', 'power_budget_confirmed', 'olt_tx_power_dbm', 'onu_tx_power_dbm', 'onu_rx_sensitivity_dbm', 'olt_rx_sensitivity_dbm'];

    protected function casts(): array
    {
        return ['fiber_schema_locked' => 'boolean', 'fiber_schema_locked_at' => 'datetime', 'fiber_budget_limit_db' => 'float', 'fiber_schema_layout' => 'array', 'fiber_attenuation_1310_db_km' => 'float', 'fiber_attenuation_1490_db_km' => 'float', 'fiber_attenuation_1577_db_km' => 'float', 'connector_loss_db' => 'float', 'splice_allowance_db' => 'float', 'engineering_margin_db' => 'float', 'additional_passive_loss_db' => 'float', 'power_budget_confirmed' => 'boolean', 'olt_tx_power_dbm' => 'float', 'onu_tx_power_dbm' => 'float', 'onu_rx_sensitivity_dbm' => 'float', 'olt_rx_sensitivity_dbm' => 'float'];
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
