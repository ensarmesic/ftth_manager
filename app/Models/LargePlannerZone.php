<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LargePlannerZone extends Model
{
    public const STATUSES = ['draft', 'calculated', 'accepted', 'locked'];

    protected $attributes = ['status' => 'draft'];

    protected $fillable = ['name', 'geometry', 'status'];

    protected function casts(): array
    {
        return ['geometry' => 'array'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function isLocked(): bool
    {
        return $this->status === 'locked';
    }

    public function houses(): HasMany
    {
        return $this->hasMany(House::class);
    }
}
