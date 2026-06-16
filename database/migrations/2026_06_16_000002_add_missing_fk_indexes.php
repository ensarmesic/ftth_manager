<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('odfs', function (Blueprint $table): void {
            $table->index('project_id', 'odfs_project_id_idx');
        });

        Schema::table('cabinets', function (Blueprint $table): void {
            $table->index('project_id', 'cabinets_project_id_idx');
            $table->index('odf_id', 'cabinets_odf_id_idx');
        });

        Schema::table('subscribers', function (Blueprint $table): void {
            $table->index('project_id', 'subscribers_project_id_idx');
            $table->index('cabinet_id', 'subscribers_cabinet_id_idx');
        });
    }

    public function down(): void
    {
        Schema::table('odfs', function (Blueprint $table): void {
            $table->dropIndex('odfs_project_id_idx');
        });

        Schema::table('cabinets', function (Blueprint $table): void {
            $table->dropIndex('cabinets_project_id_idx');
            $table->dropIndex('cabinets_odf_id_idx');
        });

        Schema::table('subscribers', function (Blueprint $table): void {
            $table->dropIndex('subscribers_project_id_idx');
            $table->dropIndex('subscribers_cabinet_id_idx');
        });
    }
};
