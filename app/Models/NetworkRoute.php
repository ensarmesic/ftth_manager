<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NetworkRoute extends Model
{
    use HasFactory;

    protected $table = 'routes';

    protected $fillable = ['project_id', 'odf_id', 'cabinet_id', 'from_type', 'from_id', 'to_type', 'to_id', 'name', 'route_type', 'installation_type', 'trench_group', 'counts_as_trench', 'trench_length_m', 'duct_length_m', 'fiber_length_m', 'fiber_count', 'microduct_count', 'microduct_type', 'cable_type', 'status', 'path', 'coordinates_json', 'note', 'import_batch'];

    public function trenchLengthForReport(): int
    {
        if ($this->route_type !== 'trench') {
            return 0;
        }

        return (int) $this->duct_length_m;
    }

    public function hasCompleteMicroductData(): bool
    {
        return filled($this->microduct_type)
            && (int) $this->microduct_count > 0
            && (float) $this->duct_length_m > 0;
    }

    public function hasIncompleteCableData(): bool
    {
        return (int) $this->fiber_count > 0 && (float) $this->fiber_length_m <= 0;
    }

    protected function casts(): array
    {
        return [
            'path' => 'array',
            'coordinates_json' => 'array',
            'counts_as_trench' => 'boolean',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<Odf, $this> */
    public function odf(): BelongsTo
    {
        return $this->belongsTo(Odf::class);
    }

    /** @return BelongsTo<Cabinet, $this> */
    public function cabinet(): BelongsTo
    {
        return $this->belongsTo(Cabinet::class);
    }

    /** @return BelongsTo<House, $this> */
    public function house(): BelongsTo
    {
        return $this->belongsTo(House::class, 'to_id');
    }

    /** @return BelongsTo<Cabinet, $this> */
    public function fromCabinet(): BelongsTo
    {
        return $this->belongsTo(Cabinet::class, 'from_id');
    }

    public function getFromLabelAttribute(): string
    {
        if ($this->from_type === 'cabinet') {
            return $this->fromCabinet?->name ?? '-';
        }

        return $this->odf?->name ?? '-';
    }
}
