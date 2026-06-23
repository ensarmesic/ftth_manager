<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cabinets', function (Blueprint $table): void {
            $table->index('branch_id', 'cabinets_branch_id_idx');
        });

        Schema::table('houses', function (Blueprint $table): void {
            $table->index('branch_id', 'houses_branch_id_idx');
        });

        Schema::table('network_branches', function (Blueprint $table): void {
            $table->index('odf_id', 'branches_odf_id_idx');
        });
    }

    public function down(): void
    {
        Schema::table('cabinets', function (Blueprint $table): void {
            $table->dropIndex('cabinets_branch_id_idx');
        });

        Schema::table('houses', function (Blueprint $table): void {
            $table->dropIndex('houses_branch_id_idx');
        });

        Schema::table('network_branches', function (Blueprint $table): void {
            $table->dropIndex('branches_odf_id_idx');
        });
    }
};
