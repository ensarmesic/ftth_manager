<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NetworkRoute extends Model
{
    protected $table = 'routes';
    protected $fillable = ['project_id', 'odf_id', 'cabinet_id', 'from_type', 'from_id', 'to_type', 'to_id', 'name', 'route_type', 'installation_type', 'trench_group', 'counts_as_trench', 'trench_length_m', 'duct_length_m', 'fiber_length_m', 'fiber_count', 'microduct_count', 'microduct_type', 'cable_type', 'status', 'path', 'coordinates_json', 'note'];

    public function trenchLengthForReport(): int
    {
        if ($this->route_type !== 'trench') {
            return 0;
        }

        return (int) $this->duct_length_m;
    }

    protected function casts(): array
    {
        return [
            'path' => 'array',
            'coordinates_json' => 'array',
            'counts_as_trench' => 'boolean',
        ];
    }

    public function project(): BelongsTo { return $this->belongsTo(Project::class); }
    public function odf(): BelongsTo { return $this->belongsTo(Odf::class); }
    public function cabinet(): BelongsTo { return $this->belongsTo(Cabinet::class); }

    public function getFromLabelAttribute(): string
    {
        if ($this->from_type === 'cabinet' && $this->from_id) {
            return Cabinet::query()->whereKey($this->from_id)->value('name') ?? '-';
        }

        if ($this->from_type === 'odf' && $this->from_id) {
            return Odf::query()->whereKey($this->from_id)->value('name') ?? '-';
        }

        return $this->odf?->name ?? '-';
    }
}
