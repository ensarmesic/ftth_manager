<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Subscriber extends Model
{
    protected $fillable = ['project_id', 'cabinet_id', 'name', 'address', 'phone', 'service_status', 'splitter_no', 'port_no', 'connected_at'];

    public function project(): BelongsTo { return $this->belongsTo(Project::class); }
    public function cabinet(): BelongsTo { return $this->belongsTo(Cabinet::class); }
}
