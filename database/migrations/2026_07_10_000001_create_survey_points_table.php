<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('survey_points', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('import_batch', 64)->index(); // hash fajla — sprjecava dupli uvoz
            $table->string('source_file')->nullable();
            $table->unsignedInteger('point_no');
            $table->decimal('x', 12, 3); // GK easting
            $table->decimal('y', 12, 3); // GK northing
            $table->decimal('z', 8, 3)->nullable();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->string('code', 255)->default(''); // originalni opis geodete
            $table->string('kind', 30)->index(); // trench|cabinet|odf|manhole|splice|sling|boring|pole|other
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('survey_points');
    }
};
