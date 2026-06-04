<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cabinets', function (Blueprint $table): void {
            $table->foreignId('parent_cabinet_id')
                ->nullable()
                ->after('odf_id')
                ->constrained('cabinets')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('cabinets', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('parent_cabinet_id');
        });
    }
};
