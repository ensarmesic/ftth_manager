<?php

namespace App\Http\Controllers;

use App\Models\Cabinet;
use App\Models\House;
use App\Models\MapDraft;
use App\Models\Material;
use App\Models\NetworkBranch;
use App\Models\NetworkRoute;
use App\Models\Odf;
use App\Models\Project;
use App\Models\Subscriber;
use App\Services\FtthIntelligenceService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use App\Http\Controllers\Concerns\ManagesFtthData;

class HouseController extends Controller
{
    use ManagesFtthData;

    public function houses(): View
    {
        return view('ftth.houses', [
            'houses' => House::with(['project', 'cabinet'])->latest()->paginate(12),
            'projects' => Project::orderBy('name')->get(),
            'cabinets' => Cabinet::with(['project'])->withCount('houses')->orderBy('name')->get(),
            'houseStats' => [
                'total' => House::count(),
                'connected' => House::whereNotNull('cabinet_id')->count(),
                'unassigned' => House::whereNull('cabinet_id')->count(),
                'cabinets' => Cabinet::count(),
            ],
        ]);
    }

    public function storeHouse(Request $request)
    {
        $data = $request->validate([
            'project_id' => ['required', 'exists:projects,id'],
            'cabinet_id' => ['nullable', 'exists:cabinets,id'],
            'label' => ['required', 'max:255'],
            'address' => ['nullable', 'max:255'],
            'latitude' => $this->latitudeRules(true),
            'longitude' => $this->longitudeRules(true),
            'status' => ['required', 'in:planned,connected,cancelled'],
        ]);
        $this->ensureBelongsToProject(Cabinet::class, $data['cabinet_id'] ?? null, $data['project_id'], 'cabinet_id');
        $this->ensureCabinetHouseCapacity($data['cabinet_id'] ?? null);
        $house = House::create($data);

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Kuca/prikljucak je evidentiran.', 'house' => [
                'id' => $house->id, 'name' => $house->label, 'address' => $house->address, 'cabinet_id' => $house->cabinet_id,
                'cabinet' => $house->cabinet?->name ?? 'Nije povezano', 'status' => $house->status,
                'lat' => (float) $house->latitude, 'lng' => (float) $house->longitude,
            ]], 201);
        }

        return back()->with('success', 'Kuca/prikljucak je evidentiran.');
    }

    public function deleteHouse($id)
    {
        House::findOrFail($id)->delete();
        if (request()->expectsJson()) {
            return response()->json(['message' => 'Kuca je obrisana.']);
        }

        return back()->with('success', 'Kuca je obrisana.');
    }

    public function updateHouse(Request $request, $id): RedirectResponse
    {
        $house = House::findOrFail($id);
        $data = $request->validate([
            'project_id' => ['required', 'exists:projects,id'],
            'cabinet_id' => ['nullable', 'exists:cabinets,id'],
            'label' => ['required', 'max:255'],
            'address' => ['nullable', 'max:255'],
            'latitude' => $this->latitudeRules(true),
            'longitude' => $this->longitudeRules(true),
            'status' => ['required', 'in:planned,connected,cancelled'],
        ]);
        $this->ensureBelongsToProject(Cabinet::class, $data['cabinet_id'] ?? null, $data['project_id'], 'cabinet_id');
        $this->ensureCabinetHouseCapacity($data['cabinet_id'] ?? null, $house->id);
        $house->update($data);

        return back()->with('success', 'Kuca je azurirana.');
    }

    public function updateHousePosition(Request $request, $id)
    {
        return $this->updatePosition($request, House::findOrFail($id));
    }

    public function connectCabinetHouses(Request $request, $id)
    {
        $cabinet = Cabinet::withCount('houses')->findOrFail($id);
        $data = $request->validate(['house_ids' => ['required', 'array', 'min:1'], 'house_ids.*' => ['integer', 'distinct', 'exists:houses,id']]);
        $houses = House::whereIn('id', $data['house_ids'])->get();

        if ($houses->contains(fn (House $house) => $house->project_id !== $cabinet->project_id)) {
            return response()->json(['message' => 'ODO i sve kuce moraju pripadati istom projektu.'], 422);
        }
        if ($houses->contains(fn (House $house) => $house->cabinet_id && $house->cabinet_id !== $cabinet->id)) {
            return response()->json(['message' => 'Jedna ili vise kuca vec su povezane na drugi ODO.'], 422);
        }
        $newHouses = $houses->whereNull('cabinet_id');
        if ($cabinet->houses_count + $newHouses->count() > $cabinet->capacity) {
            return response()->json(['message' => "ODO ne moze imati vise od {$cabinet->capacity} kuca."], 422);
        }

        $routes = DB::transaction(function () use ($cabinet, $newHouses) {
            return $newHouses->map(function (House $house) use ($cabinet) {
                $path = $this->dropPathForHouse($cabinet, $house);
                $length = $this->polylineLength($path);
                $house->update(['cabinet_id' => $cabinet->id]);
                $dropName = $this->uniqueProjectName(NetworkRoute::class, $cabinet->project_id, "Drop {$cabinet->name}-{$house->label}");

                return NetworkRoute::create([
                    'project_id' => $cabinet->project_id, 'cabinet_id' => $cabinet->id,
                    'from_type' => 'cabinet', 'from_id' => $cabinet->id, 'to_type' => 'house', 'to_id' => $house->id,
                    'name' => $dropName, 'route_type' => 'drop', 'installation_type' => 'underground',
                    'duct_length_m' => $length, 'fiber_length_m' => $length, 'fiber_count' => 4,
                    'microduct_count' => 1, 'microduct_type' => '10/8', 'status' => 'planned', 'path' => $path,
                ]);
            });
        });

        return response()->json(['message' => 'Kuce i drop trase su povezane.', 'routes' => $routes->values()]);
    }

    private function dropPathForHouse(Cabinet $cabinet, House $house): array
    {
        $directPath = [
            [(float) $cabinet->latitude, (float) $cabinet->longitude],
            [(float) $house->latitude, (float) $house->longitude],
        ];

        $route = NetworkRoute::query()
            ->where('project_id', $cabinet->project_id)
            ->whereNotIn('route_type', ['trench', 'drop'])
            ->whereNotNull('path')
            ->get()
            ->filter(fn (NetworkRoute $route) => count($route->path ?? []) >= 2)
            ->sortBy(fn (NetworkRoute $route) => $this->projectPointToRoute((float) $cabinet->latitude, (float) $cabinet->longitude, $route)['distance_m'])
            ->first();

        if (! $route) {
            return $directPath;
        }

        $cabinetProjection = $this->projectPointToRoute((float) $cabinet->latitude, (float) $cabinet->longitude, $route);
        $houseProjection = $this->projectPointToRoute((float) $house->latitude, (float) $house->longitude, $route);
        if ($cabinetProjection['distance_m'] > 35 || $houseProjection['distance_m'] > 90) {
            return $directPath;
        }

        return $this->compactPath(array_merge(
            [[(float) $cabinet->latitude, (float) $cabinet->longitude], [$cabinetProjection['lat'], $cabinetProjection['lng']]],
            array_slice($this->routePathBetween($route, $cabinetProjection, $houseProjection), 1),
            [[(float) $house->latitude, (float) $house->longitude]]
        ));
    }

    public function subscribers(): View
    {
        return view('ftth.subscribers', [
            'subscribers' => Subscriber::with(['project', 'cabinet'])->latest()->paginate(12),
            'projects' => Project::orderBy('name')->get(),
            'cabinets' => Cabinet::withCount('subscribers')->orderBy('name')->get(),
            'subscriberStats' => [
                'total' => Subscriber::count(),
                'in_service' => Subscriber::where('service_status', 'in_service')->count(),
                'planned' => Subscriber::where('service_status', 'planned')->count(),
                'cabinets' => Cabinet::count(),
            ],
        ]);
    }

    public function storeSubscriber(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'project_id' => ['required', 'exists:projects,id'],
            'cabinet_id' => ['nullable', 'exists:cabinets,id'],
            'name' => ['required', 'max:255'],
            'address' => ['required', 'max:255'],
            'latitude' => $this->latitudeRules(),
            'longitude' => $this->longitudeRules(),
            'phone' => ['nullable', 'max:50'],
            'service_status' => ['required', 'in:planned,connected,in_service,cancelled'],
            'splitter_no' => ['nullable', 'integer', 'min:1', 'max:3'],
            'port_no' => ['nullable', 'integer', 'min:1', 'max:4'],
            'connected_at' => ['nullable', 'date'],
        ]);
        $this->ensureBelongsToProject(Cabinet::class, $data['cabinet_id'] ?? null, $data['project_id'], 'cabinet_id');

        if (! empty($data['cabinet_id'])) {
            $cabinet = Cabinet::withCount('subscribers')->findOrFail($data['cabinet_id']);
            if ($cabinet->subscribers_count >= $cabinet->capacity) {
                return back()->withErrors(['cabinet_id' => 'Odabrani ormaric je popunjen. Potrebno je planirati novi ormaric.'])->withInput();
            }
        }

        Subscriber::create($data);

        return back()->with('success', 'Korisnik je evidentiran.');
    }

    public function updateSubscriber(Request $request, $id): RedirectResponse
    {
        $subscriber = Subscriber::findOrFail($id);
        $data = $request->validate([
            'project_id' => ['required', 'exists:projects,id'],
            'cabinet_id' => ['nullable', 'exists:cabinets,id'],
            'name' => ['required', 'max:255'],
            'address' => ['required', 'max:255'],
            'latitude' => $this->latitudeRules(),
            'longitude' => $this->longitudeRules(),
            'phone' => ['nullable', 'max:50'],
            'service_status' => ['required', 'in:planned,connected,in_service,cancelled'],
            'splitter_no' => ['nullable', 'integer', 'min:1', 'max:3'],
            'port_no' => ['nullable', 'integer', 'min:1', 'max:4'],
            'connected_at' => ['nullable', 'date'],
        ]);
        $this->ensureBelongsToProject(Cabinet::class, $data['cabinet_id'] ?? null, $data['project_id'], 'cabinet_id');
        if (! empty($data['cabinet_id'])) {
            $cabinet = Cabinet::withCount(['subscribers' => fn ($query) => $query->whereKeyNot($subscriber->id)])->findOrFail($data['cabinet_id']);
            if ($cabinet->subscribers_count >= $cabinet->capacity) {
                return back()->withErrors(['cabinet_id' => 'Odabrani ormaric je popunjen.'])->withInput();
            }
        }
        $subscriber->update($data);

        return back()->with('success', 'Korisnik je azuriran.');
    }

    public function deleteSubscriber($id)
    {
        Subscriber::findOrFail($id)->delete();

        return back()->with('success', 'Korisnik je obrisan.');
    }
}

