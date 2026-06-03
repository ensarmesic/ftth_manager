<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NetworkRoute extends Model
{
    protected $table = 'routes';
    protected $fillable = ['project_id', 'odf_id', 'cabinet_id', 'from_type', 'from_id', 'to_type', 'to_id', 'name', 'route_type', 'installation_type', 'duct_length_m', 'fiber_length_m', 'fiber_count', 'microduct_count', 'microduct_type', 'cable_type', 'status', 'path', 'coordinates_json', 'note'];

    protected function casts(): array
    {
        return [
            'path' => 'array',
            'coordinates_json' => 'array',
        ];
    }

    public function project(): BelongsTo { return $this->belongsTo(Project::class); }
    public function odf(): BelongsTo { return $this->belongsTo(Odf::class); }
    public function cabinet(): BelongsTo { return $this->belongsTo(Cabinet::class); }
}
