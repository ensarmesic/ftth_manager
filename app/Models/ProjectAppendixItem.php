<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectAppendixItem extends Model
{
    protected $fillable = [
        'project_id',
        'type',
        'quantity',
        'unit',
        'latitude',
        'longitude',
        'length_m',
        'angle_deg',
        'width_m',
        'note',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
