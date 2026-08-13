<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->boolean('fiber_schema_locked')->default(false);
            $table->timestamp('fiber_schema_locked_at')->nullable();
            $table->foreignId('fiber_schema_locked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('fiber_budget_limit_db', 5, 2)->default(28);
        });

        Schema::create('fiber_splices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cabinet_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('fiber_number');
            $table->unsignedSmallInteger('tray')->default(1);
            $table->unsignedSmallInteger('position')->default(1);
            $table->string('incoming_label')->nullable();
            $table->string('outgoing_label')->nullable();
            $table->decimal('loss_db', 5, 3)->default(.1);
            $table->text('note')->nullable();
            $table->timestamps();
            $table->unique(['project_id', 'cabinet_id', 'fiber_number']);
        });

        Schema::create('fiber_schema_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('label');
            $table->json('payload');
            $table->timestamps();
            $table->index(['project_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiber_schema_versions');
        Schema::dropIfExists('fiber_splices');
        Schema::table('projects', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('fiber_schema_locked_by');
            $table->dropColumn(['fiber_schema_locked', 'fiber_schema_locked_at', 'fiber_budget_limit_db']);
        });
    }
};
