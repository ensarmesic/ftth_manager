<?php

use App\Services\SurveyPointClassifier;
use App\Support\SurveyCategories;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Pole labels such as "S.R. + S.E." or "drveni s.r." used to be imported as unknown points.
return new class extends Migration
{
    public function up(): void
    {
        $classifier = app(SurveyPointClassifier::class);
        $nextPoleNo = [];
        DB::table('survey_points')
            ->where(fn ($query) => $query->where('survey_category', 'unknown_survey_point')
                ->orWhere(fn ($query) => $query->whereNull('survey_category')->where('kind', 'pole')))
            ->whereNotNull('code')
            ->orderBy('project_id')->orderBy('point_no')->orderBy('id')
            ->get(['id', 'project_id', 'code'])
            ->each(function (object $point) use ($classifier, &$nextPoleNo): void {
                $category = $classifier->surveyObjectCategory((string) $point->code);
                if ($category === null) {
                    return;
                }
                $attributes = ['kind' => 'survey_object', 'survey_category' => $category];
                if (SurveyCategories::isPole($category)) {
                    $nextPoleNo[$point->project_id] ??= (int) DB::table('survey_points')->where('project_id', $point->project_id)->max('pole_no') + 1;
                    $attributes['pole_no'] = $nextPoleNo[$point->project_id]++;
                }
                DB::table('survey_points')->where('id', $point->id)->update($attributes);
            });
    }

    public function down(): void
    {
        // Data fix only.
    }
};
