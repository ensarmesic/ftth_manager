<?php

namespace App\Http\Controllers;

use App\Models\Cabinet;
use App\Models\House;
use App\Models\NetworkRoute;
use App\Models\Odf;
use App\Models\Project;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GlobalSearchController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $term = trim($request->string('q')->toString());
        if (mb_strlen($term) < 2) {
            return response()->json(['items' => []]);
        }
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';
        $items = collect();

        if (preg_match('/^(-?\d{1,2}(?:\.\d+)?)\s*[,; ]\s*(-?\d{1,3}(?:\.\d+)?)$/', $term, $match)) {
            $lat = (float) $match[1];
            $lng = (float) $match[2];
            if ($lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180) {
                $items->push($this->item('Koordinate', number_format($lat, 6).', '.number_format($lng, 6), 'Otvori i centriraj mapu', route('map.dashboard', ['lat' => $lat, 'lng' => $lng, 'zoom' => 20])));
            }
        }

        Project::query()->where(fn (Builder $query) => $query->where('name', 'like', $like)->orWhere('code', 'like', $like)->orWhere('location', 'like', $like))
            ->limit(5)->get()->each(fn (Project $item) => $items->push($this->item('Projekat', $item->name, trim($item->code.' · '.$item->location, ' ·'), route('projects.show', $item))));
        Odf::query()->with('project:id,name')->where(fn (Builder $query) => $query->where('name', 'like', $like)->orWhere('address', 'like', $like))
            ->limit(5)->get()->each(fn (Odf $item) => $items->push($this->item('ODF', $item->name, $item->project?->name.' · '.$item->address, route('map.dashboard', ['project' => $item->project_id]))));
        Cabinet::query()->with('project:id,name')->where(fn (Builder $query) => $query->where('name', 'like', $like)->orWhere('address', 'like', $like))
            ->limit(5)->get()->each(fn (Cabinet $item) => $items->push($this->item('ODO', $item->name, $item->project?->name.' · '.$item->address, route('map.dashboard', ['project' => $item->project_id]))));
        House::query()->with('project:id,name')->where(fn (Builder $query) => $query->where('label', 'like', $like)->orWhere('address', 'like', $like))
            ->limit(5)->get()->each(fn (House $item) => $items->push($this->item('Kuća', $item->label, $item->project?->name.' · '.$item->address, route('map.dashboard', ['project' => $item->project_id]))));
        NetworkRoute::query()->with('project:id,name')->where(fn (Builder $query) => $query->where('name', 'like', $like)->orWhere('note', 'like', $like))
            ->limit(5)->get()->each(fn (NetworkRoute $item) => $items->push($this->item('Trasa', $item->name, $item->project?->name.' · '.$item->route_type, route('map.dashboard', ['project' => $item->project_id]))));

        return response()->json(['items' => $items->take(20)->values()]);
    }

    private function item(string $type, string $label, ?string $subtitle, string $url): array
    {
        return compact('type', 'label', 'subtitle', 'url');
    }
}
