<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaterialEstimateVersion extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['items' => 'array', 'planned_total' => 'decimal:2', 'used_total' => 'decimal:2'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
