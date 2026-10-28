<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pole_branches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('pole_branches')->cascadeOnDelete();
            // Pole on the parent branch (or the start pole) this branch hangs from.
            $table->foreignId('from_point_id')->nullable()->constrained('survey_points')->nullOnDelete();
            $table->string('code', 40);
            $table->unsignedSmallInteger('depth')->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->unsignedInteger('pole_count')->default(0);
            $table->decimal('length_m', 10, 2)->default(0);
            $table->json('long_spans')->nullable();
            $table->decimal('max_span_m', 6, 1);
            // Branches starting from an underground line: feeding ODF and where the cable leaves the line.
            $table->foreignId('odf_id')->nullable()->constrained('odfs')->nullOnDelete();
            $table->foreignId('route_id')->nullable()->constrained('routes')->nullOnDelete();
            $table->decimal('entry_lat', 11, 8)->nullable();
            $table->decimal('entry_lng', 11, 8)->nullable();
            $table->decimal('entry_length_m', 8, 1)->nullable();
            $table->timestamps();
            $table->unique(['project_id', 'code']);
        });

        Schema::table('survey_points', function (Blueprint $table): void {
            // No foreign key: on SQLite that would rebuild the whole table. PoleBranchService clears it.
            $table->unsignedBigInteger('pole_branch_id')->nullable()->after('pole_no')->index();
            $table->unsignedInteger('branch_position')->nullable()->after('pole_branch_id');
        });
    }

    public function down(): void
    {
        Schema::table('survey_points', function (Blueprint $table): void {
            $table->dropIndex(['pole_branch_id']);
            $table->dropColumn(['pole_branch_id', 'branch_position']);
        });
        Schema::dropIfExists('pole_branches');
    }
};
