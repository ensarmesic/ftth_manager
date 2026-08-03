<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SurveyPoint extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id', 'import_batch', 'source_file', 'point_no',
        'x', 'y', 'z', 'latitude', 'longitude', 'code', 'kind',
        'source', 'session_uuid', 'sequence', 'accuracy_m', 'note', 'photo_path', 'captured_at',
    ];

    protected function casts(): array
    {
        return [
            'captured_at' => 'datetime',
            'accuracy_m' => 'float',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
