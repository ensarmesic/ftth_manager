<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('routes', function (Blueprint $table): void {
            $table->string('from_type')->nullable()->after('cabinet_id');
            $table->unsignedBigInteger('from_id')->nullable()->after('from_type');
            $table->string('to_type')->nullable()->after('from_id');
            $table->unsignedBigInteger('to_id')->nullable()->after('to_type');
            $table->json('coordinates_json')->nullable()->after('path');
            $table->string('cable_type')->nullable()->after('microduct_type');
        });
    }

    public function down(): void
    {
        Schema::table('routes', function (Blueprint $table): void {
            $table->dropColumn(['from_type', 'from_id', 'to_type', 'to_id', 'coordinates_json', 'cable_type']);
        });
    }
};
