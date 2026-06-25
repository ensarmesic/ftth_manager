<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Material extends Model
{
    protected $fillable = ['project_id', 'name', 'unit', 'planned_quantity', 'used_quantity', 'unit_price'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
