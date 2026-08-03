<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('survey_points', function (Blueprint $table): void {
            $table->string('source', 20)->default('txt')->index();
            $table->uuid('session_uuid')->nullable()->index();
            $table->unsignedInteger('sequence')->nullable();
            $table->decimal('accuracy_m', 8, 2)->nullable();
            $table->text('note')->nullable();
            $table->string('photo_path')->nullable();
            $table->timestamp('captured_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('survey_points', function (Blueprint $table): void {
            $table->dropIndex(['source']);
            $table->dropIndex(['session_uuid']);
            $table->dropColumn(['source', 'session_uuid', 'sequence', 'accuracy_m', 'note', 'photo_path', 'captured_at']);
        });
    }
};
