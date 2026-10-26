<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('survey_points', function (Blueprint $table): void {
            $table->string('survey_category', 60)->nullable()->after('kind')->index();
            $table->string('original_label')->nullable()->after('survey_category');
            $table->string('source_crs', 32)->nullable()->after('original_label');
            $table->timestamp('imported_at')->nullable()->after('source_crs');
        });
    }

    public function down(): void
    {
        Schema::table('survey_points', function (Blueprint $table): void {
            $table->dropIndex(['survey_category']);
            $table->dropColumn(['survey_category', 'original_label', 'source_crs', 'imported_at']);
        });
    }
};
