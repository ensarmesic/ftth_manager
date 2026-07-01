<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gis_restricted_areas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('name')->nullable();
            $table->string('source')->default('geojson');
            $table->string('area_type')->default('restricted');
            $table->json('polygon');
            $table->json('properties')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'area_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gis_restricted_areas');
    }
};
