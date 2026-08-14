<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('heavy', fn (Request $request) => Limit::perMinute(12)->by($request->user()?->id ?: $request->ip()));

        DB::listen(function (QueryExecuted $query): void {
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
