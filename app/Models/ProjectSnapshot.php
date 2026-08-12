<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProjectSnapshot extends Model
{
    protected $fillable = ['project_id', 'label', 'payload'];

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }

    public function contentSummary(): array
    {
        return collect($this->payload ?? [])->map(fn ($rows) => count($rows))->filter()->all();
    }

    public function source(): string
    {
        return str_starts_with($this->label, 'Automatski:') ? 'automatic' : 'manual';
    }
}
