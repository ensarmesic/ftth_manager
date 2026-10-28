<?php

namespace App\Http\Controllers;

use App\Models\PoleBranchLink;
use App\Models\Project;
use App\Services\PoleBranchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class PoleBranchController extends Controller
{
    public function __construct(private readonly PoleBranchService $branches) {}

    public function generate(Request $request, Project $project): JsonResponse
    {
        $data = $request->validate([
            'max_span_m' => ['nullable', 'numeric', 'between:10,300'],
            'root_point_id' => ['nullable', 'integer'],
        ]);

        try {
            $summary = $this->branches->generate(
                $project,
                isset($data['root_point_id']) ? (int) $data['root_point_id'] : null,
                (float) ($data['max_span_m'] ?? PoleBranchService::DEFAULT_MAX_SPAN_M),
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['message' => $this->summaryMessage($summary), 'summary' => $summary]);
    }

    /** Manual correction: always use ("connect") or never use ("cut") the span between two poles. */
    public function link(Request $request, Project $project): JsonResponse
    {
        $data = $request->validate([
            'from_point_id' => ['required', 'integer'],
            'to_point_id' => ['required', 'integer'],
            'type' => ['required', 'in:'.PoleBranchLink::CONNECT.','.PoleBranchLink::CUT],
        ]);

        try {
            $summary = $this->branches->link($project, (int) $data['from_point_id'], (int) $data['to_point_id'], $data['type']);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
        $action = $data['type'] === PoleBranchLink::CUT ? 'Veza prekinuta' : 'Stubovi spojeni';

        return response()->json(['message' => $action.'. '.($summary ? $this->summaryMessage($summary) : ''), 'summary' => $summary]);
    }

    /** Removes one manual correction, or all of them when no link is given. */
    public function unlink(Request $request, Project $project): JsonResponse
    {
        $data = $request->validate(['link_id' => ['nullable', 'integer']]);
        $summary = $this->branches->unlink($project, isset($data['link_id']) ? (int) $data['link_id'] : null);
        $what = isset($data['link_id']) ? 'Ručna izmjena poništena' : 'Sve ručne izmjene krakova poništene';

        return response()->json(['message' => $what.'. '.($summary ? $this->summaryMessage($summary) : ''), 'summary' => $summary]);
    }

    private function summaryMessage(array $summary): string
    {
        $total = number_format($summary['length_m'], 0, ',', '.');
        $message = $summary['risers'] > 0
            ? "Krakovi napravljeni od podzemne linije: {$summary['branches']}, prelaza na stub: {$summary['risers']}, stubova u krakovima: {$summary['poles']}, preskočeno uz podzemnu liniju: {$summary['skipped']}, ukupno nadzemno {$total} m."
            : "Krakovi napravljeni: {$summary['branches']}, stubova: {$summary['poles']}, početni stub {$summary['root_label']}, ukupno {$total} m.";
        if ($summary['long_spans'] > 0) {
            $message .= " Predugi rasponi za provjeru: {$summary['long_spans']} (označeni crveno).";
        }

        return $message;
    }

    public function destroy(Project $project): JsonResponse
    {
        $removed = $this->branches->clear($project);

        return response()->json(['message' => "Obrisani krakovi: {$removed}. Stubovi su ostali."]);
    }
}
