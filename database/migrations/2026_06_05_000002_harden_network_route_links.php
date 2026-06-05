<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('network_branches', function (Blueprint $table) {
            $table->unique('route_id');
        });

        Schema::table('routes', function (Blueprint $table) {
            $table->index(['from_type', 'from_id']);
            $table->index(['to_type', 'to_id']);
        });
    }

    public function down(): void
    {
        Schema::table('network_branches', function (Blueprint $table) {
            $table->dropUnique(['route_id']);
        });

        Schema::table('routes', function (Blueprint $table) {
            $table->dropIndex(['from_type', 'from_id']);
            $table->dropIndex(['to_type', 'to_id']);
        });
    }
};
