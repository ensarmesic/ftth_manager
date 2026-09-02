<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ManagesFtthData;
use App\Models\Cabinet;
use App\Models\House;
use App\Models\NetworkBranch;
use App\Models\NetworkRoute;
use App\Models\Odf;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CabinetController extends Controller
{
    use ManagesFtthData;

    public function cabinets(Request $request): View
    {
        $projectId = Project::query()->whereKey($request->integer('project'))->value('id');
        $cabinetUsage = Cabinet::query()
            ->when($projectId, fn ($query) => $query->where('cabinets.project_id', $projectId))
            ->leftJoin('houses', 'houses.cabinet_id', '=', 'cabinets.id')
            ->groupBy('cabinets.id', 'cabinets.splitter_count', 'cabinets.ports_per_splitter')
            ->selectRaw('cabinets.splitter_count * cabinets.ports_per_splitter AS capacity')
            ->selectRaw('COUNT(houses.id) AS used_ports');

        $stats = DB::query()->fromSub($cabinetUsage, 'cabinet_usage')
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw('COALESCE(SUM(capacity), 0) AS capacity')
            ->selectRaw('COALESCE(SUM(used_ports), 0) AS used_ports')
            ->selectRaw('COALESCE(SUM(CASE WHEN used_ports >= capacity THEN 1 ELSE 0 END), 0) AS full')
            ->first();
        $cabinetStats = [
            'total' => (int) $stats->total,
            'capacity' => (int) $stats->capacity,
            'used_ports' => (int) $stats->used_ports,
            'full' => (int) $stats->full,
        ];

        return view('ftth.cabinets', [
            'cabinets' => Cabinet::with(['project', 'odf', 'branch', 'parentCabinet', 'childCabinets'])->withCount(['houses'])
                ->when($projectId, fn ($query) => $query->where('project_id', $projectId))
                ->when($request->filled('q'), fn ($query) => $query->where(fn ($search) => $search
                    ->where('name', 'like', '%'.$request->string('q')->trim().'%')->orWhere('address', 'like', '%'.$request->string('q')->trim().'%')
                    ->orWhereHas('project', fn ($project) => $project->where('name', 'like', '%'.$request->string('q')->trim().'%'))))
                ->latest()->paginate(12)->withQueryString(),
            'parentCabinets' => Cabinet::with('project')->when($projectId, fn ($query) => $query->where('project_id', $projectId))->orderBy('name')->get(),
            'branches' => NetworkBranch::with('project')->where('type', 'secondary')->when($projectId, fn ($query) => $query->where('project_id', $projectId))->orderBy('sort_order')->orderBy('name')->get(),
            'projects' => Project::orderBy('name')->get(),
            'odfs' => Odf::with('project')->when($projectId, fn ($query) => $query->where('project_id', $projectId))->orderBy('name')->get(),
            'cabinetStats' => $cabinetStats,
            'selectedProject' => $projectId ? Project::find($projectId) : null,
            'projectContext' => $this->projectWorkspaceContext($projectId),
        ]);
    }

    public function storeCabinet(Request $request)
    {
        $data = $request->validate([
            'project_id' => ['required', 'exists:projects,id'],
            'odf_id' => ['nullable', 'exists:odfs,id'],
            'parent_cabinet_id' => ['nullable', 'exists:cabinets,id'],
            'branch_id' => ['nullable', 'exists:network_branches,id'],
            'branch_order' => ['nullable', 'integer', 'min:0'],
            'name' => ['required', 'max:255'],
            'address' => ['required', 'max:255'],
            'splitter_count' => ['required', 'integer', 'min:1', 'max:3'],
            'ports_per_splitter' => ['required', 'integer', 'min:1', 'max:4'],
            'latitude' => $this->latitudeRules(),
            'longitude' => $this->longitudeRules(),
        ]);
        $this->ensureBelongsToProject(Odf::class, $data['odf_id'] ?? null, $data['project_id'], 'odf_id');
        $this->ensureBelongsToProject(Cabinet::class, $data['parent_cabinet_id'] ?? null, $data['project_id'], 'parent_cabinet_id');
        $this->ensureBelongsToProject(NetworkBranch::class, $data['branch_id'] ?? null, $data['project_id'], 'branch_id');
        $this->ensureSecondaryBranch($data['branch_id'] ?? null);
        $cabinet = Cabinet::create($data);

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Ormaric je dodat.', 'cabinet' => [
                'id' => $cabinet->id, 'name' => $cabinet->name, 'address' => $cabinet->address,
                'odf' => $cabinet->odf?->name ?? 'Nije povezano', 'parent_cabinet_id' => $cabinet->parent_cabinet_id,
                'parent_cabinet' => $cabinet->parentCabinet?->name, 'fed_from' => $cabinet->parentCabinet?->name ?? ($cabinet->odf?->name ?? 'Nije povezano'), 'used' => 0, 'capacity' => $cabinet->capacity,
                'splitters' => $cabinet->splitter_count, 'lat' => (float) $cabinet->latitude, 'lng' => (float) $cabinet->longitude,
            ]], 201);
        }

        return back()->with('success', 'Ormaric je dodat.');
    }

    public function deleteCabinet($id)
    {
        $cabinet = Cabinet::findOrFail($id);
        $connectedRoutes = NetworkRoute::query()->where('project_id', $cabinet->project_id)
            ->where(fn ($query) => $query->where('cabinet_id', $cabinet->id)
                ->orWhere(fn ($routeQuery) => $routeQuery->where('from_type', 'cabinet')->where('from_id', $cabinet->id))
                ->orWhere(fn ($routeQuery) => $routeQuery->where('to_type', 'cabinet')->where('to_id', $cabinet->id)))
            ->get();
        $connectedRoutes->where('route_type', 'drop')->each(fn (NetworkRoute $route) => $this->deleteRouteWithBranch($route));
        $connectedRoutes->where('route_type', '!=', 'drop')->each(function (NetworkRoute $route) use ($cabinet): void {
            $updates = [];
            if ((int) $route->cabinet_id === (int) $cabinet->id) {
                $updates['cabinet_id'] = null;
            }
            if ($route->from_type === 'cabinet' && (int) $route->from_id === (int) $cabinet->id) {
                $updates['from_type'] = null;
                $updates['from_id'] = null;
            }
            if ($route->to_type === 'cabinet' && (int) $route->to_id === (int) $cabinet->id) {
                $updates['to_type'] = null;
                $updates['to_id'] = null;
            }
            if ($updates) {
                $route->update($updates);
            }
        });
        $cabinet->houses()->update(['cabinet_id' => null]);
        $cabinet->childCabinets()->update(['parent_cabinet_id' => null]);
        $cabinet->delete();
        if (request()->expectsJson()) {
            return response()->json(['message' => 'Ormaric i njegove drop trase su obrisani.']);
        }

        return back()->with('success', 'Ormaric je obrisan.');
    }

    public function updateCabinet(Request $request, $id): RedirectResponse|JsonResponse
    {
        $cabinet = Cabinet::findOrFail($id);
        $data = $request->validate([
            'project_id' => ['required', Rule::in([$cabinet->project_id])],
            'odf_id' => ['nullable', 'exists:odfs,id'],
            'parent_cabinet_id' => ['nullable', 'exists:cabinets,id'],
            'branch_id' => ['nullable', 'exists:network_branches,id'],
            'branch_order' => ['nullable', 'integer', 'min:0'],
            'name' => ['required', 'max:255'],
            'address' => ['required', 'max:255'],
            'splitter_count' => ['required', 'integer', 'min:1', 'max:3'],
            'ports_per_splitter' => ['required', 'integer', 'min:1', 'max:4'],
            'latitude' => $this->latitudeRules(),
            'longitude' => $this->longitudeRules(),
        ]);
        $this->ensureBelongsToProject(Odf::class, $data['odf_id'] ?? null, $data['project_id'], 'odf_id');
        $this->ensureBelongsToProject(Cabinet::class, $data['parent_cabinet_id'] ?? null, $data['project_id'], 'parent_cabinet_id');
        $this->ensureBelongsToProject(NetworkBranch::class, $data['branch_id'] ?? null, $data['project_id'], 'branch_id');
        $this->ensureSecondaryBranch($data['branch_id'] ?? null);
        if ((int) ($data['parent_cabinet_id'] ?? 0) === (int) $cabinet->id) {
            return back()->withErrors(['parent_cabinet_id' => 'Ormaric ne moze napajati sam sebe.'])->withInput();
        }
        if ($this->cabinetWouldCreateCycle($cabinet->id, $data['parent_cabinet_id'] ?? null)) {
            return back()->withErrors(['parent_cabinet_id' => 'Odabrani roditeljski ormaric bi napravio kruzno napajanje.'])->withInput();
        }
        if ($cabinet->houses()->count() > $data['splitter_count'] * $data['ports_per_splitter']) {
            return back()->withErrors(['splitter_count' => 'Novi kapacitet je manji od broja dodijeljenih kuca.'])->withInput();
        }
        $cabinet->update($data);

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Ormarić je ažuriran.', 'item' => $cabinet->fresh()]);
        }

        return back()->with('success', 'Ormarić je ažuriran.');
    }

    public function updateCabinetPosition(Request $request, $id)
    {
        return $this->updatePosition($request, Cabinet::findOrFail($id));
    }

    public function storeSuggestedCabinets(Request $request)
    {
        $data = $request->validate([
            'project_id' => ['required', 'exists:projects,id'],
            'cabinets' => ['required', 'array', 'min:1'],
            'cabinets.*.name' => ['required', 'max:255'],
            'cabinets.*.latitude' => $this->latitudeRules(true),
            'cabinets.*.longitude' => $this->longitudeRules(true),
            'cabinets.*.splitter_count' => ['required', 'integer', 'min:1', 'max:3'],
            'cabinets.*.odf_id' => ['nullable', 'integer', 'exists:odfs,id'],
            'cabinets.*.houses' => ['nullable', 'array'],
            'cabinets.*.houses.*.id' => ['nullable', 'integer', 'distinct', 'exists:houses,id'],
            'cabinets.*.houses.*.latitude' => $this->latitudeRules(true),
            'cabinets.*.houses.*.longitude' => $this->longitudeRules(true),
            'cabinets.*.houses.*.path' => ['nullable', 'array', 'min:2'],
            'cabinets.*.houses.*.path.*' => ['required', 'array', 'size:2'],
            'cabinets.*.houses.*.path.*.*' => ['required', 'numeric'],
        ]);

        $projectId = $data['project_id'];
        $submittedHouseIds = collect($data['cabinets'])
            ->flatMap(fn (array $cabinet) => collect($cabinet['houses'] ?? [])->pluck('id'))
            ->filter()
            ->map(fn ($id) => (int) $id);
        $projectHouseCount = House::query()
            ->where('project_id', $projectId)
            ->whereIn('id', $submittedHouseIds)
            ->count();
        if ($projectHouseCount !== $submittedHouseIds->unique()->count()) {
            throw ValidationException::withMessages([
                'cabinets' => 'Sve odabrane kuce moraju pripadati izabranom projektu.',
            ]);
        }

        $createdCount = 0;
        $linkedHouseCount = 0;
        $createdRouteCount = 0;

        DB::transaction(function () use ($data, $projectId, &$createdCount, &$linkedHouseCount, &$createdRouteCount): void {
            foreach ($data['cabinets'] as $cabinet) {
                $this->ensureBelongsToProject(Odf::class, $cabinet['odf_id'] ?? null, $projectId, 'odf_id');
                $houseIds = collect($cabinet['houses'] ?? [])->pluck('id')->filter()->values();
                $assignedCabinetIds = $houseIds->isEmpty()
                    ? collect()
                    : House::query()
                        ->where('project_id', $projectId)
                        ->whereIn('id', $houseIds)
                        ->whereNotNull('cabinet_id')
                        ->pluck('cabinet_id')
                        ->unique()
                        ->values();

                $createdCabinet = null;
                $cabinetName = trim((string) ($cabinet['name'] ?? ''));
                if ($cabinetName === '') {
                    $cabinetName = $this->nextFtthCabinetNameForProject($projectId);
                }
                if ($assignedCabinetIds->count() === 1) {
                    $createdCabinet = Cabinet::query()
                        ->where('project_id', $projectId)
                        ->whereKey($assignedCabinetIds->first())
                        ->first();
                }

                if (! $createdCabinet) {
                    $createdCabinet = Cabinet::query()
                        ->where('project_id', $projectId)
                        ->where('name', $cabinetName)
                        ->first();
                }

                if ($createdCabinet) {
                    $resultingHouseCount = $createdCabinet->houses()
                        ->whereNotIn('id', $houseIds)
                        ->count() + $houseIds->count();
                    $capacity = (int) $cabinet['splitter_count'] * 4;
                    if ($resultingHouseCount > $capacity) {
                        throw ValidationException::withMessages([
                            'cabinets' => "ODO {$cabinetName} ima kapacitet {$capacity}, a raspored bi mu dodijelio {$resultingHouseCount} kuca.",
                        ]);
                    }
                    $createdCabinet->update([
                        'odf_id' => $cabinet['odf_id'] ?? null,
                        'address' => 'Sa mape - '.$cabinet['latitude'].','.$cabinet['longitude'],
                        'splitter_count' => $cabinet['splitter_count'],
                        'ports_per_splitter' => 4,
                        'latitude' => $cabinet['latitude'],
                        'longitude' => $cabinet['longitude'],
                    ]);
                } else {
                    $capacity = (int) $cabinet['splitter_count'] * 4;
                    if ($houseIds->count() > $capacity) {
                        throw ValidationException::withMessages([
                            'cabinets' => "ODO {$cabinetName} ima kapacitet {$capacity}, a odabrano je {$houseIds->count()} kuca.",
                        ]);
                    }
                    $createdCabinet = Cabinet::create([
                        'project_id' => $projectId,
                        'odf_id' => $cabinet['odf_id'] ?? null,
                        'name' => $this->uniqueProjectName(Cabinet::class, $projectId, $cabinetName),
                        'address' => 'Sa mape - '.$cabinet['latitude'].','.$cabinet['longitude'],
                        'splitter_count' => $cabinet['splitter_count'],
                        'ports_per_splitter' => 4,
                        'latitude' => $cabinet['latitude'],
                        'longitude' => $cabinet['longitude'],
                    ]);
                    $createdCount++;
                }

                foreach ($cabinet['houses'] ?? [] as $index => $point) {
                    $house = ! empty($point['id'])
                        ? House::query()->where('project_id', $projectId)->whereKey($point['id'])->first()
                        : House::query()
                            ->where('project_id', $projectId)
                            ->where('latitude', $point['latitude'])
                            ->where('longitude', $point['longitude'])
                            ->first();
                    if (! $house) {
                        continue;
                    }

                    if ((int) $house->cabinet_id === (int) $createdCabinet->id) {
                        continue;
                    }

                    NetworkRoute::query()
                        ->where('project_id', $projectId)
                        ->where('route_type', 'drop')
                        ->where('to_type', 'house')
                        ->where('to_id', $house->id)
                        ->get()
                        ->each(fn (NetworkRoute $route) => $this->deleteRouteWithBranch($route));
                    $house->update(['cabinet_id' => $createdCabinet->id]);
                    $path = $point['path'] ?? [[(float) $createdCabinet->latitude, (float) $createdCabinet->longitude], [(float) $house->latitude, (float) $house->longitude]];
                    $length = $this->polylineLength($path);
                    $dropName = $this->uniqueProjectName(NetworkRoute::class, $projectId, "Drop {$createdCabinet->name}-{$house->label}");
                    NetworkRoute::create([
                        'project_id' => $projectId,
                        'cabinet_id' => $createdCabinet->id,
                        'from_type' => 'cabinet',
                        'from_id' => $createdCabinet->id,
                        'to_type' => 'house',
                        'to_id' => $house->id,
                        'name' => $dropName,
                        'route_type' => 'drop',
                        'installation_type' => 'underground',
                        'duct_length_m' => $length,
                        'fiber_length_m' => $length,
                        'fiber_count' => 4,
                        'microduct_count' => 1,
                        'microduct_type' => '10/8',
                        'status' => 'planned',
                        'path' => $path,
                    ]);
                    $linkedHouseCount++;
                    $createdRouteCount++;
                }
            }
        });

        return response()->json([
            'message' => "Kreirano {$createdCount} ormarica.",
            'created' => $createdCount,
            'linked_houses' => $linkedHouseCount,
            'created_routes' => $createdRouteCount,
        ]);
    }
}
