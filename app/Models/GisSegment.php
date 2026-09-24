<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GisSegment extends Model
{
    public const PLANNING_CORRIDOR_TYPES = ['main', 'primary', 'secondary'];

    protected $fillable = [
        'project_id',
        'name',
        'source',
        'segment_type',
        'is_allowed',
        'planning_corridor_type',
        'length_m',
        'path',
        'properties',
    ];

    protected function casts(): array
    {
        return [
            'is_allowed' => 'boolean',
            'path' => 'array',
            'properties' => 'array',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
