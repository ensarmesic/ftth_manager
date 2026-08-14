<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->string('pon_profile', 30)->default('gpon_b_plus');
            $table->decimal('fiber_attenuation_1310_db_km', 5, 3)->default(.400);
            $table->decimal('fiber_attenuation_1490_db_km', 5, 3)->default(.300);
            $table->decimal('fiber_attenuation_1577_db_km', 5, 3)->default(.300);
            $table->decimal('connector_loss_db', 5, 3)->default(.500);
            $table->unsignedTinyInteger('connector_count')->default(2);
            $table->decimal('splice_allowance_db', 5, 3)->default(.100);
            $table->unsignedSmallInteger('planned_splice_count')->default(2);
            $table->decimal('engineering_margin_db', 5, 2)->default(3);
        });
    }

    public function down(): void
    {
        Schema::table('projects', fn (Blueprint $table) => $table->dropColumn([
            'pon_profile', 'fiber_attenuation_1310_db_km', 'fiber_attenuation_1490_db_km',
            'fiber_attenuation_1577_db_km', 'connector_loss_db', 'connector_count',
            'splice_allowance_db', 'planned_splice_count', 'engineering_margin_db',
        ]));
    }
};
