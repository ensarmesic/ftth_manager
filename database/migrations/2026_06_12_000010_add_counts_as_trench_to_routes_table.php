<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('routes', function (Blueprint $table): void {
            $table->boolean('counts_as_trench')->default(true)->after('trench_group');
        });

        DB::table('routes')->whereNull('counts_as_trench')->update(['counts_as_trench' => true]);
    }

    public function down(): void
    {
        Schema::table('routes', function (Blueprint $table): void {
            $table->dropColumn('counts_as_trench');
        });
    }
};
