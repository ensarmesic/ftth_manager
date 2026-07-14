<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Services\SurveyPointImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class SurveyPointController extends Controller
{
    public function __construct(private readonly SurveyPointImportService $importer) {}

    public function preview(Request $request, Project $project): JsonResponse
    {
        $data = $request->validate([
            'points_file' => ['required', 'file', 'max:10240', 'mimes:txt'],
        ]);

        try {
            return response()->json($this->importer->preview(
                $project,
                $data['points_file']->get(),
                $data['points_file']->getClientOriginalName(),
            ));
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function import(Request $request, Project $project): JsonResponse
    {
        $data = $request->validate([
            'points_file' => ['required', 'file', 'max:10240', 'mimes:txt'],
            'overrides' => ['nullable', 'string'],
        ]);

        $cabinetOverrides = [];
        if (! empty($data['overrides'])) {
            $decoded = json_decode($data['overrides'], true);
            if (is_array($decoded)) {
                $cabinetOverrides = array_map('intval', $decoded);
            }
        }

        try {
            $created = $this->importer->confirm(
                $project,
                $data['points_file']->get(),
                $data['points_file']->getClientOriginalName(),
                $cabinetOverrides,
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'message' => "Uvezeno: {$created['points']} tacaka, {$created['trenches']} rovova, {$created['cabinets']} ZO, {$created['odfs']} ODF, {$created['manholes']} sahtova, {$created['splices']} spojnica, {$created['borings']} busenja, {$created['loops']} rezervi.",
            'created' => $created,
        ], 201);
    }

    public function destroy(Project $project): JsonResponse
    {
        $removed = $this->importer->clearImportedData($project);

        return response()->json([
            'message' => "Obrisano: {$removed['points']} tacaka, {$removed['trenches']} rovova, {$removed['ducts']} mikrocijevi, {$removed['cabinets']} ZO, {$removed['odfs']} ODF, {$removed['houses']} kuca, {$removed['manholes']} sahtova, {$removed['splices']} spojnica, {$removed['borings']} busenja, {$removed['loops']} rezervi. Rucno nacrtani elementi nisu dirani.",
            'removed' => $removed,
        ]);
    }
}
