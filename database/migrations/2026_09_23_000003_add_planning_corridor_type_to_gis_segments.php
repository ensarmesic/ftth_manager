<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gis_segments', function (Blueprint $table): void {
            $table->string('planning_corridor_type', 20)->nullable()->after('is_allowed');
            $table->index(['project_id', 'planning_corridor_type'], 'gis_segments_project_planning_corridor_idx');
        });
    }

    public function down(): void
    {
        Schema::table('gis_segments', function (Blueprint $table): void {
            $table->dropIndex('gis_segments_project_planning_corridor_idx');
            $table->dropColumn('planning_corridor_type');
        });
    }
};
