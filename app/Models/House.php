<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class House extends Model
{
    use HasFactory;

    protected $fillable = ['project_id', 'cabinet_id', 'branch_id', 'label', 'address', 'latitude', 'longitude', 'status', 'import_batch'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function cabinet(): BelongsTo
    {
        return $this->belongsTo(Cabinet::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(NetworkBranch::class, 'branch_id');
    }
}
