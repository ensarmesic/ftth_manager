<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class MonitorPerformance
{
    public function handle(Request $request, Closure $next): Response
    {
        $startedAt = hrtime(true);
        $response = $next($request);
        $durationMs = (hrtime(true) - $startedAt) / 1_000_000;

        if ($durationMs >= (float) env('SLOW_REQUEST_MS', 1500)) {
            Log::warning('Spor HTTP zahtjev', [
                'method' => $request->method(),
                'route' => $request->route()?->getName(),
                'path' => '/'.$request->path(),
                'duration_ms' => round($durationMs, 1),
                'user_id' => $request->user()?->id,
            ]);
        }

        $response->headers->set('Server-Timing', 'app;dur='.round($durationMs, 1));

        return $response;
    }
}
