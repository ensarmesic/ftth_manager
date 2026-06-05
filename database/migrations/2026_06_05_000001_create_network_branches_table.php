<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('network_branches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('odf_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('parent_branch_id')->nullable()->constrained('network_branches')->nullOnDelete();
            $table->foreignId('route_id')->nullable()->constrained('routes')->nullOnDelete();
            $table->string('name');
            $table->string('code')->nullable();
            $table->string('type')->default('secondary');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::table('cabinets', function (Blueprint $table) {
            $table->foreignId('branch_id')->nullable()->after('parent_cabinet_id')->constrained('network_branches')->nullOnDelete();
            $table->unsignedInteger('branch_order')->default(0)->after('branch_id');
        });

        DB::table('routes')->whereIn('route_type', ['backbone', 'feeder', 'distribution'])->orderBy('id')->each(function ($route): void {
            $branchId = DB::table('network_branches')->insertGetId([
                'project_id' => $route->project_id,
                'odf_id' => $route->odf_id,
                'route_id' => $route->id,
                'name' => $route->name,
                'code' => preg_match('/(\d+(?:\.\d+)*)/', $route->name, $match) ? $match[1] : null,
                'type' => in_array($route->route_type, ['backbone', 'feeder'], true) ? 'primary' : 'secondary',
                'sort_order' => $route->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            if (preg_match('/(\d+(?:\.\d+)*)/', $route->name, $match)) {
                DB::table('cabinets')->where('project_id', $route->project_id)
                    ->where(function ($query) use ($match) {
                        $query->where('name', 'like', "FTTH {$match[1]}-%")->orWhere('name', 'like', "FTTH-{$match[1]}-%");
                    })->update(['branch_id' => $branchId]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('cabinets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('branch_id');
            $table->dropColumn('branch_order');
        });
        Schema::dropIfExists('network_branches');
    }
};
