<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class House extends Model
{
    protected $fillable = ['project_id', 'cabinet_id', 'label', 'address', 'latitude', 'longitude', 'status'];

    public function project(): BelongsTo { return $this->belongsTo(Project::class); }
    public function cabinet(): BelongsTo { return $this->belongsTo(Cabinet::class); }
}
