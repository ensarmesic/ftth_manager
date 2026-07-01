<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gis_segments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('name')->nullable();
            $table->string('source')->default('geojson');
            $table->string('segment_type')->default('road');
            $table->boolean('is_allowed')->default(true);
            $table->unsignedInteger('length_m')->default(0);
            $table->json('path');
            $table->json('properties')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'segment_type']);
            $table->index(['project_id', 'is_allowed']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gis_segments');
    }
};
