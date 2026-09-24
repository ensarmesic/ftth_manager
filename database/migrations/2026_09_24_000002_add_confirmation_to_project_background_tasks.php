<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_background_tasks', function (Blueprint $table): void {
            $table->timestamp('confirmed_at')->nullable()->index();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('snapshot_id')->nullable()->constrained('project_snapshots')->nullOnDelete();
            $table->json('confirmation_summary')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('project_background_tasks', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('snapshot_id');
            $table->dropConstrainedForeignId('confirmed_by');
            $table->dropColumn(['confirmed_at', 'confirmation_summary']);
        });
    }
};
