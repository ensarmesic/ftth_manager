<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A review note drawn on the map: a point, line or area with a short instruction
 * about what should change there. Anyone who can open the project may write and resolve notes.
 *
 * @property array<int, array{0: float, 1: float}> $geometry
 * @property Carbon|null $resolved_at
 */
class MapNote extends Model
{
    public const GEOMETRY_TYPES = ['point', 'line', 'area'];

    public const KINDS = [
        'general' => 'Opšte',
        'move' => 'Pomjeri',
        'add' => 'Dodaj',
        'remove' => 'Obriši',
        'reconnect' => 'Prespoji',
        'route' => 'Trasa',
    ];

    public const OPEN = 'open';

    public const RESOLVED = 'resolved';

    protected $fillable = ['project_id', 'user_id', 'geometry_type', 'geometry', 'kind', 'body', 'status', 'resolved_by', 'resolved_at'];

    protected function casts(): array
    {
        return ['geometry' => 'array', 'resolved_at' => 'datetime'];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /** @return array<string, mixed> */
    public function toMapArray(): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'geometry_type' => $this->geometry_type,
            'geometry' => $this->geometry,
            'kind' => $this->kind,
            'kind_label' => self::KINDS[$this->kind] ?? $this->kind,
            'body' => $this->body,
            'status' => $this->status,
            'author' => $this->user?->name,
            'resolved_by' => $this->resolver?->name,
            'resolved_at' => $this->resolved_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
