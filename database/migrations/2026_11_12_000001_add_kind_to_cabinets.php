<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A cabinet is either a closure in the ground (ZO, default) or a splitter box
 * mounted on a pole ("box na stubu"); a box remembers the surveyed pole it
 * hangs on so both are drawn as one symbol.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cabinets', function (Blueprint $table): void {
            $table->string('kind', 16)->default('zo')->after('name');
            $table->unsignedBigInteger('survey_point_id')->nullable()->after('kind');
        });
    }

    public function down(): void
    {
        Schema::table('cabinets', function (Blueprint $table): void {
            $table->dropColumn(['kind', 'survey_point_id']);
        });
    }
};
