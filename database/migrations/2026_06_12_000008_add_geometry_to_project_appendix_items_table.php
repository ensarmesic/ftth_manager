<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_appendix_items', function (Blueprint $table) {
            $table->decimal('length_m', 10, 2)->nullable()->after('longitude');
            $table->decimal('angle_deg', 7, 2)->nullable()->after('length_m');
            $table->decimal('width_m', 8, 2)->nullable()->after('angle_deg');
        });
    }

    public function down(): void
    {
        Schema::table('project_appendix_items', function (Blueprint $table) {
            $table->dropColumn(['length_m', 'angle_deg', 'width_m']);
        });
    }
};
