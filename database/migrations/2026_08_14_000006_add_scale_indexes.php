<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cabinets', function (Blueprint $table): void {
            $table->index(['project_id', 'parent_cabinet_id'], 'cabinets_project_parent_idx');
            $table->index(['project_id', 'branch_id', 'branch_order'], 'cabinets_project_branch_order_idx');
        });
        Schema::table('houses', function (Blueprint $table): void {
            $table->index(['project_id', 'status'], 'houses_project_status_idx');
            $table->index(['project_id', 'branch_id'], 'houses_project_branch_idx');
        });
        Schema::table('routes', function (Blueprint $table): void {
            $table->index(['project_id', 'status'], 'routes_project_status_idx');
            $table->index(['project_id', 'cabinet_id', 'route_type'], 'routes_project_cabinet_type_idx');
        });
        Schema::table('network_branches', function (Blueprint $table): void {
            $table->index(['project_id', 'sort_order'], 'branches_project_sort_idx');
            $table->index(['project_id', 'parent_branch_id'], 'branches_project_parent_idx');
        });
        Schema::table('survey_points', function (Blueprint $table): void {
            $table->index(['project_id', 'import_batch'], 'survey_project_batch_idx');
            $table->index(['project_id', 'kind'], 'survey_project_kind_idx');
        });
        Schema::table('map_drafts', function (Blueprint $table): void {
            $table->index(['project_id', 'updated_at'], 'map_drafts_project_updated_idx');
        });
    }

    public function down(): void
    {
        Schema::table('cabinets', fn (Blueprint $table) => $table->dropIndex('cabinets_project_parent_idx'));
        Schema::table('cabinets', fn (Blueprint $table) => $table->dropIndex('cabinets_project_branch_order_idx'));
        Schema::table('houses', fn (Blueprint $table) => $table->dropIndex('houses_project_status_idx'));
        Schema::table('houses', fn (Blueprint $table) => $table->dropIndex('houses_project_branch_idx'));
        Schema::table('routes', fn (Blueprint $table) => $table->dropIndex('routes_project_status_idx'));
        Schema::table('routes', fn (Blueprint $table) => $table->dropIndex('routes_project_cabinet_type_idx'));
        Schema::table('network_branches', fn (Blueprint $table) => $table->dropIndex('branches_project_sort_idx'));
        Schema::table('network_branches', fn (Blueprint $table) => $table->dropIndex('branches_project_parent_idx'));
        Schema::table('survey_points', fn (Blueprint $table) => $table->dropIndex('survey_project_batch_idx'));
        Schema::table('survey_points', fn (Blueprint $table) => $table->dropIndex('survey_project_kind_idx'));
        Schema::table('map_drafts', fn (Blueprint $table) => $table->dropIndex('map_drafts_project_updated_idx'));
    }
};
