<?php

namespace App\Http\Middleware;

use App\Models\ActivityLog;
use App\Models\Project;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class AuditMutations
{
    public function handle(Request $request, Closure $next): Response
    {
        $parameters = collect($request->route()?->parameters() ?? []);
        $subject = $parameters->first(fn ($value) => $value instanceof Model);
        $before = $subject ? $this->safeAttributes($subject->getAttributes()) : null;
        $response = $next($request);
        if ($request->user() && ! in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true) && $response->getStatusCode() < 400) {
            Cache::forget('dashboard:aggregates:v1');
            $project = $parameters->first(fn ($value) => $value instanceof Project);
            $after = null;
            if ($subject && $subject->newQuery()->whereKey($subject->getKey())->exists()) {
                $after = $this->safeAttributes($subject->fresh()->getAttributes());
            }
            $changes = $before === null ? null : $this->changes($before, $after);
            ActivityLog::create([
                'user_id' => $request->user()->id,
                'project_id' => $project?->id ?? $request->integer('project_id') ?: null,
                'method' => $request->method(), 'route_name' => $request->route()?->getName(),
                'path' => '/'.$request->path(), 'subject_type' => $subject ? class_basename($subject) : null,
                'subject_id' => $subject?->getKey(), 'status_code' => $response->getStatusCode(),
                'metadata' => [
                    'event' => 'mutation',
                    'fields' => collect($request->except(['_token', 'password', 'password_confirmation', 'current_password']))->keys()->all(),
                    'changes' => $changes,
                ],
                'ip_address' => $request->ip(),
            ]);
        }

        return $response;
    }

    private function safeAttributes(array $attributes): array
    {
        return collect($attributes)
            ->except(['password', 'remember_token', 'two_factor_secret'])
            ->map(fn ($value) => is_string($value) && strlen($value) > 1000 ? substr($value, 0, 1000).'…' : $value)
            ->all();
    }

    private function changes(array $before, ?array $after): array
    {
        if ($after === null) {
            return ['before' => $before, 'after' => null];
        }
        $keys = collect(array_unique([...array_keys($before), ...array_keys($after)]))
            ->filter(fn ($key) => ($before[$key] ?? null) !== ($after[$key] ?? null));

        return [
            'before' => $keys->mapWithKeys(fn ($key) => [$key => $before[$key] ?? null])->all(),
            'after' => $keys->mapWithKeys(fn ($key) => [$key => $after[$key] ?? null])->all(),
        ];
    }
}
