<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FiberSplice extends Model
{
    protected $fillable = ['project_id', 'cabinet_id', 'fiber_number', 'tray', 'position', 'incoming_label', 'outgoing_label', 'loss_db', 'note'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function cabinet(): BelongsTo
    {
        return $this->belongsTo(Cabinet::class);
    }
}
