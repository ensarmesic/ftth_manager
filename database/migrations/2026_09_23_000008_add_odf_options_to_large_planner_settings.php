<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('large_planner_settings', function (Blueprint $table): void {
            $table->boolean('propose_odfs')->default(false);
            $table->unsignedSmallInteger('odf_capacity')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('large_planner_settings', function (Blueprint $table): void {
            $table->dropColumn(['propose_odfs', 'odf_capacity']);
        });
    }
};
