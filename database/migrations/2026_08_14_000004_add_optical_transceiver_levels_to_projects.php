<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->decimal('olt_tx_power_dbm', 6, 2)->nullable();
            $table->decimal('onu_tx_power_dbm', 6, 2)->nullable();
            $table->decimal('onu_rx_sensitivity_dbm', 6, 2)->nullable();
            $table->decimal('olt_rx_sensitivity_dbm', 6, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('projects', fn (Blueprint $table) => $table->dropColumn(['olt_tx_power_dbm', 'onu_tx_power_dbm', 'onu_rx_sensitivity_dbm', 'olt_rx_sensitivity_dbm']));
    }
};
