<?php

namespace App\Http\Middleware;

use App\Models\ActivityLog;
use App\Models\Project;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuditMutations
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if ($request->user() && ! in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true) && $response->getStatusCode() < 400) {
            $parameters = collect($request->route()?->parameters() ?? []);
            $subject = $parameters->first(fn ($value) => $value instanceof Model);
            $project = $parameters->first(fn ($value) => $value instanceof Project);
            ActivityLog::create([
                'user_id' => $request->user()->id,
                'project_id' => $project?->id ?? $request->integer('project_id') ?: null,
                'method' => $request->method(), 'route_name' => $request->route()?->getName(),
                'path' => '/'.$request->path(), 'subject_type' => $subject ? class_basename($subject) : null,
                'subject_id' => $subject?->getKey(), 'status_code' => $response->getStatusCode(),
                'metadata' => ['fields' => collect($request->except(['_token', 'password', 'password_confirmation', 'current_password']))->keys()->all()],
                'ip_address' => $request->ip(),
            ]);
        }

        return $response;
    }
}
