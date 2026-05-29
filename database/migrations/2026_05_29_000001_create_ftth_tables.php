<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->string('location');
            $table->string('status')->default('planning');
            $table->date('start_date')->nullable();
            $table->date('deadline')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('odfs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('address');
            $table->unsignedInteger('fiber_capacity')->default(144);
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->timestamps();
        });

        Schema::create('cabinets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('odf_id')->nullable()->constrained('odfs')->nullOnDelete();
            $table->string('name');
            $table->string('address');
            $table->unsignedTinyInteger('splitter_count')->default(3);
            $table->unsignedTinyInteger('ports_per_splitter')->default(4);
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->timestamps();
        });

        Schema::create('subscribers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cabinet_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('address');
            $table->string('phone')->nullable();
            $table->string('service_status')->default('planned');
            $table->unsignedTinyInteger('splitter_no')->nullable();
            $table->unsignedTinyInteger('port_no')->nullable();
            $table->date('connected_at')->nullable();
            $table->timestamps();
        });

        Schema::create('routes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('odf_id')->nullable()->constrained('odfs')->nullOnDelete();
            $table->foreignId('cabinet_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('route_type')->default('distribution');
            $table->unsignedInteger('duct_length_m')->default(0);
            $table->unsignedInteger('fiber_length_m')->default(0);
            $table->unsignedInteger('microduct_count')->default(1);
            $table->string('status')->default('planned');
            $table->timestamps();
        });

        Schema::create('materials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('unit')->default('kom');
            $table->decimal('planned_quantity', 12, 2)->default(0);
            $table->decimal('used_quantity', 12, 2)->default(0);
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('materials');
        Schema::dropIfExists('routes');
        Schema::dropIfExists('subscribers');
        Schema::dropIfExists('cabinets');
        Schema::dropIfExists('odfs');
        Schema::dropIfExists('projects');
    }
};
