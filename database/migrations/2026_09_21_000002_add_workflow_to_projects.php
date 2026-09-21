<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->string('workflow_stage', 24)->default('draft')->index();
            $table->timestamp('workflow_changed_at')->nullable();
            $table->foreignId('workflow_changed_by')->nullable()->constrained('users')->nullOnDelete();
        });
        Schema::create('project_stage_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('from_stage', 24);
            $table->string('to_stage', 24);
            $table->string('note', 500)->nullable();
            $table->timestamps();
            $table->index(['project_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_stage_histories');
        Schema::table('projects', function (Blueprint $table): void {
            $table->dropForeign(['workflow_changed_by']);
            $table->dropIndex(['workflow_stage']);
            $table->dropColumn(['workflow_stage', 'workflow_changed_at', 'workflow_changed_by']);
        });
    }
};
