<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Review notes drawn on the map: a point, line or area with what should be changed there.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('map_notes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('geometry_type', 10); // point | line | area
            $table->json('geometry'); // [[lat, lng], ...]
            $table->string('kind', 20)->default('general');
            $table->text('body');
            $table->string('status', 10)->default('open'); // open | resolved
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['project_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('map_notes');
    }
};
