<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('investor')->nullable()->after('location');
        });

        Schema::table('odfs', function (Blueprint $table) {
            $table->unsignedInteger('port_count')->default(48)->after('fiber_capacity');
            $table->text('notes')->nullable()->after('longitude');
        });

        Schema::table('routes', function (Blueprint $table) {
            $table->string('installation_type')->default('underground')->after('route_type');
            $table->string('microduct_type')->default('14/10')->after('microduct_count');
            $table->unsignedInteger('fiber_count')->default(12)->after('fiber_length_m');
        });

    }

    public function down(): void
    {
        Schema::table('routes', function (Blueprint $table) {
            $table->dropColumn(['installation_type', 'microduct_type', 'fiber_count']);
        });


        Schema::table('odfs', function (Blueprint $table) {
            $table->dropColumn(['port_count', 'notes']);
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('investor');
        });
    }
};
