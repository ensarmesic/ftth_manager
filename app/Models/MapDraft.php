<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MapDraft extends Model
{
    protected $fillable = ['project_id', 'payload'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }

    public function project(): BelongsTo { return $this->belongsTo(Project::class); }
}
