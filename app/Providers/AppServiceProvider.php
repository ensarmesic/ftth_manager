<?php

namespace App\Providers;

use App\Models\User;
use App\Support\RequestPerformanceMetrics;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(RequestPerformanceMetrics::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        foreach (['project.view', 'project.edit', 'project.export', 'project.backup', 'field.capture', 'settings.manage', 'system.manage', 'destructive'] as $permission) {
            Gate::define($permission, fn (User $user): bool => $user->hasPermission($permission));
        }

        RateLimiter::for('heavy', fn (Request $request) => Limit::perMinute(12)->by($request->user()?->id ?: $request->ip()));

        DB::listen(function (QueryExecuted $query): void {
            app(RequestPerformanceMetrics::class)->recordQuery((float) $query->time);
            $threshold = (float) env('SLOW_QUERY_MS', 250);
            if ($query->time < $threshold) {
                return;
            }

            Log::warning('Spor SQL upit', [
                'connection' => $query->connectionName,
                'duration_ms' => round($query->time, 1),
                'sql' => mb_substr($query->sql, 0, 2000),
            ]);
        });
    }
}
