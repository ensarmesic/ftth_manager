<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class House extends Model
{
    use HasFactory;

    protected $fillable = ['project_id', 'large_planner_zone_id', 'cabinet_id', 'branch_id', 'label', 'address', 'latitude', 'longitude', 'status', 'import_batch'];

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<Cabinet, $this> */
    public function cabinet(): BelongsTo
    {
        return $this->belongsTo(Cabinet::class);
    }

    /** @return BelongsTo<NetworkBranch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(NetworkBranch::class, 'branch_id');
    }

    /** @return BelongsTo<LargePlannerZone, $this> */
    public function largePlannerZone(): BelongsTo
    {
        return $this->belongsTo(LargePlannerZone::class);
    }
}
