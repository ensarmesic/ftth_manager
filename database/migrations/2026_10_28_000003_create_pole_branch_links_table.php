<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Manual corrections of pole branches: spans the engineer forces or cuts.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pole_branch_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_point_id')->constrained('survey_points')->cascadeOnDelete();
            $table->foreignId('to_point_id')->constrained('survey_points')->cascadeOnDelete();
            $table->string('type', 10); // connect | cut
            $table->timestamps();
            $table->unique(['project_id', 'from_point_id', 'to_point_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pole_branch_links');
    }
};
