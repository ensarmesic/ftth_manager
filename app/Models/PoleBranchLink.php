<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Manual correction of pole branches: "connect" forces a span between two poles,
 * "cut" forbids it. The pair is stored with the smaller point id first.
 */
class PoleBranchLink extends Model
{
    public const CONNECT = 'connect';

    public const CUT = 'cut';

    protected $fillable = ['project_id', 'from_point_id', 'to_point_id', 'type'];

    /** @return BelongsTo<SurveyPoint, $this> */
    public function fromPoint(): BelongsTo
    {
        return $this->belongsTo(SurveyPoint::class, 'from_point_id');
    }

    /** @return BelongsTo<SurveyPoint, $this> */
    public function toPoint(): BelongsTo
    {
        return $this->belongsTo(SurveyPoint::class, 'to_point_id');
    }
}
