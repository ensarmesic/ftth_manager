<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('routes')
            ->where('route_type', 'drop')
            ->where('to_type', 'house')
            ->whereNotNull('to_id')
            ->whereNotNull('cabinet_id')
            ->orderBy('id')
            ->select(['id', 'project_id', 'to_id', 'cabinet_id'])
            ->chunkById(500, function ($routes): void {
                foreach ($routes as $route) {
                    DB::table('houses')
                        ->where('id', $route->to_id)
                        ->where('project_id', $route->project_id)
                        ->whereNull('cabinet_id')
                        ->update(['cabinet_id' => $route->cabinet_id]);
                }
            });
    }

    public function down(): void
    {
        // Existing assignments cannot be distinguished safely from backfilled ones.
    }
};
