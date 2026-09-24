<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LargePlannerInputSnapshot extends Model
{
    protected $fillable = [
        'user_id', 'revision', 'label', 'checksum', 'payload',
        'house_count', 'corridor_count', 'constraint_count', 'zone_count',
    ];

    protected function casts(): array
    {
        return ['payload' => 'array'];
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
