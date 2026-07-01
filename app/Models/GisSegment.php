<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GisSegment extends Model
{
    protected $fillable = [
        'project_id',
        'name',
        'source',
        'segment_type',
        'is_allowed',
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
