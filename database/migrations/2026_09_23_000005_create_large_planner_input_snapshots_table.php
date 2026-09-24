<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('large_planner_input_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('revision');
            $table->string('label', 120);
            $table->char('checksum', 64);
            $table->json('payload');
            $table->unsignedInteger('house_count')->default(0);
            $table->unsignedInteger('corridor_count')->default(0);
            $table->unsignedInteger('constraint_count')->default(0);
            $table->unsignedInteger('zone_count')->default(0);
            $table->timestamps();
            $table->unique(['project_id', 'revision']);
            $table->index(['project_id', 'checksum']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('large_planner_input_snapshots');
    }
};
