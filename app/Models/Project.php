<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;

class Project extends Model
{
    use HasFactory;

    /**
     * Network elements reference each other with SET NULL keys (house → ODO, ODO → ODF ...).
     * MySQL rejects such an update while the project row itself is being deleted, so they are
     * removed first, children before parents, while the project still exists.
     */
    private const DELETE_FIRST = ['fiber_splices', 'houses', 'routes', 'cabinets', 'network_branches', 'pole_branch_links', 'pole_branches', 'survey_points', 'odfs'];

    protected static function booted(): void
    {
        static::deleting(function (Project $project): void {
            foreach (self::DELETE_FIRST as $table) {
                DB::table($table)->where('project_id', $project->id)->delete();
            }
        });
    }

    public function houseDistanceLimit(): int
    {
        return (int) ($this->max_house_to_odo_m ?? 90);
    }

    protected $fillable = ['name', 'code', 'location', 'investor', 'status', 'planning_mode', 'network_installation_type', 'start_date', 'deadline', 'description', 'workflow_stage', 'workflow_changed_at', 'workflow_changed_by', 'fiber_layout', 'fiber_color_standard', 'fiber_reserve_per_tube', 'fiber_schema_locked', 'fiber_schema_locked_at', 'fiber_schema_locked_by', 'fiber_budget_limit_db', 'fiber_schema_layout', 'pon_profile', 'feeder_splitter_ratio', 'fiber_attenuation_1310_db_km', 'fiber_attenuation_1490_db_km', 'fiber_attenuation_1577_db_km', 'connector_loss_db', 'connector_count', 'splice_allowance_db', 'planned_splice_count', 'engineering_margin_db', 'additional_passive_loss_db', 'power_budget_confirmed', 'olt_tx_power_dbm', 'onu_tx_power_dbm', 'onu_rx_sensitivity_dbm', 'olt_rx_sensitivity_dbm'];

    public function isAerial(): bool
    {
        return $this->network_installation_type === 'aerial';
    }

    public function distributionPointCapacity(?int $configuredCapacity = null): int
    {
        return $this->isAerial()
            ? 4
            : max(1, $configuredCapacity ?? (int) ($this->largePlannerSetting?->odo_capacity ?? 1));
    }

    public function distributionPointLabel(): string
    {
        return $this->isAerial() ? 'ODP/NAP na stubu' : 'ODO';
    }

    protected function casts(): array
    {
        return ['workflow_changed_at' => 'datetime', 'fiber_schema_locked' => 'boolean', 'fiber_schema_locked_at' => 'datetime', 'fiber_budget_limit_db' => 'float', 'fiber_schema_layout' => 'array', 'fiber_attenuation_1310_db_km' => 'float', 'fiber_attenuation_1490_db_km' => 'float', 'fiber_attenuation_1577_db_km' => 'float', 'connector_loss_db' => 'float', 'splice_allowance_db' => 'float', 'engineering_margin_db' => 'float', 'additional_passive_loss_db' => 'float', 'power_budget_confirmed' => 'boolean', 'olt_tx_power_dbm' => 'float', 'onu_tx_power_dbm' => 'float', 'onu_rx_sensitivity_dbm' => 'float', 'olt_rx_sensitivity_dbm' => 'float'];
    }

    /** @return HasMany<Odf, $this> */
    public function odfs(): HasMany
    {
        return $this->hasMany(Odf::class);
    }

    /** @return HasMany<Cabinet, $this> */
    public function cabinets(): HasMany
    {
        return $this->hasMany(Cabinet::class);
    }

    /** @return HasMany<House, $this> */
    public function houses(): HasMany
    {
        return $this->hasMany(House::class);
    }

    /** @return HasMany<NetworkRoute, $this> */
    public function routes(): HasMany
    {
        return $this->hasMany(NetworkRoute::class);
    }

    /** @return HasMany<NetworkBranch, $this> */
    public function branches(): HasMany
    {
        return $this->hasMany(NetworkBranch::class);
    }

    /** @return HasMany<Material, $this> */
    public function materials(): HasMany
    {
        return $this->hasMany(Material::class);
    }

    /** @return HasMany<ProjectAppendixItem, $this> */
    public function appendixItems(): HasMany
    {
        return $this->hasMany(ProjectAppendixItem::class);
    }

    /** @return HasMany<GisSegment, $this> */
    public function gisSegments(): HasMany
    {
        return $this->hasMany(GisSegment::class);
    }

    /** @return HasMany<GisRestrictedArea, $this> */
    public function gisRestrictedAreas(): HasMany
    {
        return $this->hasMany(GisRestrictedArea::class);
    }

    /** @return HasMany<SurveyPoint, $this> */
    public function surveyPoints(): HasMany
    {
        return $this->hasMany(SurveyPoint::class);
    }

    /** @return HasMany<PoleBranch, $this> */
    public function poleBranches(): HasMany
    {
        return $this->hasMany(PoleBranch::class)->orderBy('sort_order');
    }

    /** @return HasMany<ProjectSnapshot, $this> */
    public function snapshots(): HasMany
    {
        return $this->hasMany(ProjectSnapshot::class);
    }

    /** @return HasMany<FiberSplice, $this> */
    public function fiberSplices(): HasMany
    {
        return $this->hasMany(FiberSplice::class);
    }

    /** @return HasMany<FiberSchemaVersion, $this> */
    public function fiberSchemaVersions(): HasMany
    {
        return $this->hasMany(FiberSchemaVersion::class);
    }

    /** @return HasMany<ProjectStageHistory, $this> */
    public function stageHistories(): HasMany
    {
        return $this->hasMany(ProjectStageHistory::class);
    }

    /** @return BelongsTo<User, $this> */
    public function workflowChangedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'workflow_changed_by');
    }

    /** @return HasMany<ProjectWorkItem, $this> */
    public function workItems(): HasMany
    {
        return $this->hasMany(ProjectWorkItem::class);
    }

    /** @return HasMany<ProjectComment, $this> */
    public function comments(): HasMany
    {
        return $this->hasMany(ProjectComment::class);
    }

    /** @return HasMany<MaterialEstimateVersion, $this> */
    public function materialEstimateVersions(): HasMany
    {
        return $this->hasMany(MaterialEstimateVersion::class);
    }

    /** @return HasMany<ProjectBackgroundTask, $this> */
    public function backgroundTasks(): HasMany
    {
        return $this->hasMany(ProjectBackgroundTask::class);
    }

    /** @return HasOne<LargePlannerSetting, $this> */
    public function largePlannerSetting(): HasOne
    {
        return $this->hasOne(LargePlannerSetting::class);
    }

    /** @return HasMany<LargePlannerConstraint, $this> */
    public function largePlannerConstraints(): HasMany
    {
        return $this->hasMany(LargePlannerConstraint::class);
    }

    /** @return HasMany<LargePlannerInputSnapshot, $this> */
    public function largePlannerInputSnapshots(): HasMany
    {
        return $this->hasMany(LargePlannerInputSnapshot::class);
    }

    /** @return HasMany<LargePlannerZone, $this> */
    public function largePlannerZones(): HasMany
    {
        return $this->hasMany(LargePlannerZone::class);
    }
}
