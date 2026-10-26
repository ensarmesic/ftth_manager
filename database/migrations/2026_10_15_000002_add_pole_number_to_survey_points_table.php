<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('survey_points', function (Blueprint $table): void {
            $table->unsignedInteger('pole_no')->nullable()->after('survey_category');
            $table->index(['project_id', 'pole_no']);
        });

        $poleCategories = collect(config('survey.categories'))->filter(fn (array $category) => $category['is_pole'] ?? false)->keys()->all();
        DB::table('survey_points')->whereIn('survey_category', $poleCategories)
            ->orderBy('project_id')->orderBy('id')->get(['id', 'project_id', 'x', 'y', 'source_crs'])
            ->groupBy('project_id')->each(function ($points): void {
                $number = 0;
                $grid = [];
                foreach ($points as $point) {
                    $crs = $point->source_crs ?: 'unknown';
                    $cellX = (int) floor((float) $point->x / 0.50);
                    $cellY = (int) floor((float) $point->y / 0.50);
                    $assigned = null;
                    for ($x = $cellX - 1; $x <= $cellX + 1 && $assigned === null; $x++) {
                        for ($y = $cellY - 1; $y <= $cellY + 1; $y++) {
                            foreach ($grid[$crs][$x][$y] ?? [] as $existing) {
                                if (hypot((float) $point->x - $existing['x'], (float) $point->y - $existing['y']) <= 0.50) {
                                    $assigned = $existing['pole_no'];
                                    break 2;
                                }
                            }
                        }
                    }
                    $assigned ??= ++$number;
                    $grid[$crs][$cellX][$cellY][] = ['x' => (float) $point->x, 'y' => (float) $point->y, 'pole_no' => $assigned];
                    DB::table('survey_points')->where('id', $point->id)->update(['pole_no' => $assigned]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('survey_points', function (Blueprint $table): void {
            $table->dropIndex(['project_id', 'pole_no']);
            $table->dropColumn('pole_no');
        });
    }
};
