<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('large_planner_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('odo_capacity')->nullable();
            $table->unsignedInteger('max_drop_length_m')->nullable();
            $table->decimal('fiber_reserve_percent', 5, 2)->nullable();
            $table->string('optimization_goal', 30)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('large_planner_settings');
    }
};
