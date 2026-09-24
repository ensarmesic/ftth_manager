<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('houses', function (Blueprint $table): void {
            $table->foreignId('large_planner_zone_id')->nullable()->after('project_id')
                ->constrained('large_planner_zones')->nullOnDelete();
            $table->index(['project_id', 'large_planner_zone_id'], 'houses_project_large_zone_idx');
        });
    }

    public function down(): void
    {
        Schema::table('houses', function (Blueprint $table): void {
            $table->dropIndex('houses_project_large_zone_idx');
            $table->dropConstrainedForeignId('large_planner_zone_id');
        });
    }
};
