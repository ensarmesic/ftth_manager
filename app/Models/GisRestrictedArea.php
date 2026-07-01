<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GisRestrictedArea extends Model
{
    protected $fillable = [
        'project_id',
        'name',
        'source',
        'area_type',
        'polygon',
        'properties',
    ];

    protected function casts(): array
    {
        return [
            'polygon' => 'array',
            'properties' => 'array',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
