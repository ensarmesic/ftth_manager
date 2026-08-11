<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('fiber_layout', 20)->default('6x24')->after('description');
            $table->string('fiber_color_standard', 20)->default('telcordia')->after('fiber_layout');
            $table->unsignedTinyInteger('fiber_reserve_per_tube')->default(0)->after('fiber_color_standard');
        });
    }

    public function down(): void
    {
        Schema::table('projects', fn (Blueprint $table) => $table->dropColumn(['fiber_layout', 'fiber_color_standard', 'fiber_reserve_per_tube']));
    }
};
