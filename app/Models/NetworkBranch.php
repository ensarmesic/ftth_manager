<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NetworkBranch extends Model
{
    protected $fillable = ['project_id', 'odf_id', 'parent_branch_id', 'route_id', 'name', 'code', 'type', 'sort_order'];

    public function project(): BelongsTo { return $this->belongsTo(Project::class); }
    public function odf(): BelongsTo { return $this->belongsTo(Odf::class); }
    public function parentBranch(): BelongsTo { return $this->belongsTo(self::class, 'parent_branch_id'); }
    public function childBranches(): HasMany { return $this->hasMany(self::class, 'parent_branch_id')->orderBy('sort_order'); }
    public function route(): BelongsTo { return $this->belongsTo(NetworkRoute::class); }
    public function cabinets(): HasMany { return $this->hasMany(Cabinet::class, 'branch_id')->orderBy('branch_order')->orderBy('name'); }
}
