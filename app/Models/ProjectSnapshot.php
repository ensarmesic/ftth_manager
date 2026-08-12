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
}
