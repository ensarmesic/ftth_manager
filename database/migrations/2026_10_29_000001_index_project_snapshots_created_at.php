<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // The dashboard lists the newest snapshots across all projects.
    public function up(): void
    {
        Schema::table('project_snapshots', function (Blueprint $table): void {
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::table('project_snapshots', function (Blueprint $table): void {
            $table->dropIndex(['created_at']);
        });
    }
};
