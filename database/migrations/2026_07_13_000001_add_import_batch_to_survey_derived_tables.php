<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('routes', function (Blueprint $table): void {
            $table->string('import_batch', 40)->nullable()->after('note');
            $table->index('import_batch', 'routes_import_batch_idx');
        });

        Schema::table('cabinets', function (Blueprint $table): void {
            $table->string('import_batch', 40)->nullable();
            $table->index('import_batch', 'cabinets_import_batch_idx');
        });

        Schema::table('odfs', function (Blueprint $table): void {
            $table->string('import_batch', 40)->nullable();
            $table->index('import_batch', 'odfs_import_batch_idx');
        });

        Schema::table('houses', function (Blueprint $table): void {
            $table->string('import_batch', 40)->nullable();
            $table->index('import_batch', 'houses_import_batch_idx');
        });

        Schema::table('project_appendix_items', function (Blueprint $table): void {
            $table->string('import_batch', 40)->nullable();
            $table->index('import_batch', 'appendix_items_import_batch_idx');
        });
    }

    public function down(): void
    {
        Schema::table('routes', function (Blueprint $table): void {
            $table->dropIndex('routes_import_batch_idx');
            $table->dropColumn('import_batch');
        });

        Schema::table('cabinets', function (Blueprint $table): void {
            $table->dropIndex('cabinets_import_batch_idx');
            $table->dropColumn('import_batch');
        });

        Schema::table('odfs', function (Blueprint $table): void {
            $table->dropIndex('odfs_import_batch_idx');
            $table->dropColumn('import_batch');
        });

        Schema::table('houses', function (Blueprint $table): void {
            $table->dropIndex('houses_import_batch_idx');
            $table->dropColumn('import_batch');
        });

        Schema::table('project_appendix_items', function (Blueprint $table): void {
            $table->dropIndex('appendix_items_import_batch_idx');
            $table->dropColumn('import_batch');
        });
    }
};
