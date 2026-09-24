<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LargePlannerSetting extends Model
{
    protected $fillable = [
        'odo_capacity',
        'max_drop_length_m',
        'fiber_reserve_percent',
        'optimization_goal',
        'propose_odfs',
        'odf_capacity',
    ];

    protected function casts(): array
    {
        return [
            'odo_capacity' => 'integer',
            'max_drop_length_m' => 'integer',
            'fiber_reserve_percent' => 'decimal:2',
            'propose_odfs' => 'boolean',
            'odf_capacity' => 'integer',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
