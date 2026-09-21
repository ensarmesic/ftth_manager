<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_work_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status', 24)->default('open')->index();
            $table->string('priority', 16)->default('normal');
            $table->date('due_date')->nullable()->index();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['project_id', 'status']);
        });
        Schema::create('project_comments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('subject_type', 32)->default('project');
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->text('body');
            $table->string('attachment_path')->nullable();
            $table->string('attachment_name')->nullable();
            $table->string('attachment_mime', 100)->nullable();
            $table->unsignedBigInteger('attachment_size')->nullable();
            $table->timestamps();
            $table->index(['project_id', 'subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_comments');
        Schema::dropIfExists('project_work_items');
    }
};
