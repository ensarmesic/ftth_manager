<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_background_tasks', function (Blueprint $table): void {
            $table->unsignedTinyInteger('progress')->default(0);
            $table->string('status_message')->nullable();
            $table->unsignedTinyInteger('attempt_count')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('project_background_tasks', function (Blueprint $table): void {
            $table->dropColumn(['progress', 'status_message', 'attempt_count']);
        });
    }
};
