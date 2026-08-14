<?php

namespace App\Http\Middleware;

use App\Support\RequestPerformanceMetrics;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class MonitorPerformance
{
    public function __construct(private readonly RequestPerformanceMetrics $metrics) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->metrics->reset();
        $startedAt = hrtime(true);
        $memoryStarted = memory_get_usage(true);
        $response = $next($request);
        $durationMs = (hrtime(true) - $startedAt) / 1_000_000;
        $databaseDurationMs = $this->metrics->databaseDurationMs();
        $responseContent = method_exists($response, 'getContent') ? $response->getContent() : false;

        if ($durationMs >= (float) env('SLOW_REQUEST_MS', 1500)) {
            Log::warning('Spor HTTP zahtjev', [
                'method' => $request->method(),
                'route' => $request->route()?->getName(),
                'path' => '/'.$request->path(),
                'duration_ms' => round($durationMs, 1),
                'query_count' => $this->metrics->queryCount(),
                'database_duration_ms' => round($databaseDurationMs, 1),
                'memory_delta_mb' => round(max(memory_get_usage(true) - $memoryStarted, 0) / 1048576, 2),
                'memory_peak_mb' => round(memory_get_peak_usage(true) / 1048576, 2),
                'response_bytes' => is_string($responseContent) ? strlen($responseContent) : null,
                'user_id' => $request->user()?->id,
            ]);
        }

        $response->headers->set('Server-Timing', 'db;dur='.round($databaseDurationMs, 1).', app;dur='.round($durationMs, 1));

        return $response;
    }
}
