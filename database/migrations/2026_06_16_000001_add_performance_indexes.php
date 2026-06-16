<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('routes', function (Blueprint $table): void {
            $table->index(['project_id', 'route_type'], 'routes_project_route_type_idx');
            $table->index(['to_type', 'to_id'], 'routes_to_type_to_id_idx');
        });

        Schema::table('houses', function (Blueprint $table): void {
            $table->index(['project_id', 'cabinet_id'], 'houses_project_cabinet_idx');
        });

        Schema::table('network_branches', function (Blueprint $table): void {
            $table->index(['project_id', 'type'], 'branches_project_type_idx');
        });
    }

    public function down(): void
    {
        Schema::table('routes', function (Blueprint $table): void {
            $table->dropIndex('routes_project_route_type_idx');
            $table->dropIndex('routes_to_type_to_id_idx');
        });

        Schema::table('houses', function (Blueprint $table): void {
            $table->dropIndex('houses_project_cabinet_idx');
        });

        Schema::table('network_branches', function (Blueprint $table): void {
            $table->dropIndex('branches_project_type_idx');
        });
    }
};
