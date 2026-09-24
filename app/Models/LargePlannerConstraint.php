<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LargePlannerConstraint extends Model
{
    public const TYPES = ['required_waypoint', 'restricted_area'];

    protected $fillable = ['type', 'name', 'geometry'];

    protected function casts(): array
    {
        return ['geometry' => 'array'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
