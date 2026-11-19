<?php

namespace App\Services\FiberSchema;

use App\Models\Cabinet;
use App\Models\NetworkBranch;
use App\Models\Odf;
use App\Models\Project;
use App\Services\BranchSourceResolver;
use App\Services\FiberPlanService;
use App\Support\FiberColorCode;
use Illuminate\Support\Collection;

/**
 * Builds the fiber (ODN) schematic once, as a list of drawing primitives on an ISO sheet.
 * The same primitives are rendered to SVG (browser, PDF) and to DXF (AutoCAD), so what the
 * engineer sees on screen is exactly what lands in the CAD file.
 *
 * Units are millimetres on paper, y grows downwards from the sheet's top-left corner.
 * The schematic is not to scale (NTS): it shows topology, not distances.
 *
 * Tree: ODF → cables (network branches) → ODO cabinets along each cable →
 * cables that leave from an ODO (branches whose route starts at a cabinet) → …
 * Cabinets without a branch, child cabinets fed from a parent cabinet and cabinets without
 * an ODF all get their own row, so no cabinet is ever left off the drawing.
 */
class FiberSchemaDrawing
{
    // Text heights (ISO 3098 series).
    public const TEXT_SMALL = 2.5;

    public const TEXT_NORMAL = 3.5;

    public const TEXT_LARGE = 5.0;

    public const TEXT_TITLE = 7.0;

    /** Layer => [AutoCAD colour index, screen colour, linetype]. */
    public const LAYERS = [
        'FO-OKVIR' => [7, '#0f172a', 'CONTINUOUS'],
        'FO-ZAGLAVLJE' => [7, '#0f172a', 'CONTINUOUS'],
        'FO-ODF' => [5, '#1d4ed8', 'CONTINUOUS'],
        'FO-KABEL-PRIMARNI' => [6, '#be185d', 'CONTINUOUS'],
        'FO-KABEL' => [4, '#0e7490', 'CONTINUOUS'],
        'FO-KABEL-ODVOJAK' => [4, '#0e7490', 'DASHED'],
        'FO-ODO' => [3, '#15803d', 'CONTINUOUS'],
        'FO-VLAKNA' => [7, '#0f172a', 'CONTINUOUS'],
        'FO-TEKST' => [7, '#0f172a', 'CONTINUOUS'],
        'FO-TABELA' => [8, '#64748b', 'CONTINUOUS'],
        'FO-UPOZORENJE' => [1, '#dc2626', 'CONTINUOUS'],
    ];

    /** ISO 216 A-series, landscape (width × height). */
    private const SHEETS = ['A3' => [420, 297], 'A2' => [594, 420], 'A1' => [841, 594], 'A0' => [1189, 841]];

    /** Fiber colour (English name) => AutoCAD colour index, for the colour squares. */
    private const ACI_BY_COLOR = ['Blue' => 5, 'Orange' => 30, 'Green' => 3, 'Brown' => 34, 'Slate' => 8, 'White' => 7, 'Red' => 1, 'Black' => 250, 'Yellow' => 2, 'Violet' => 202, 'Rose' => 221, 'Aqua' => 4];

    // Schematic geometry (mm).
    private const ROW_PITCH = 32.0;

    private const ODF_WIDTH = 44.0;

    private const ODO_HEIGHT = 12.0;

    private const ODO_MIN_WIDTH = 26.0;

    private const ODO_GAP = 22.0;

    private const FIRST_ODO_OFFSET = 42.0;

    private const SECTION_GAP = 24.0;

    private const COLUMN_GAP = 30.0;

    private const RIGHT_COLUMN = 190.0;

    private const FRAME_LEFT = 20.0;

    private const FRAME_OTHER = 10.0;

    private const PADDING = 10.0;

    private array $items = [];

    private array $plan;

    private string $colorStandard;

    private int $fibersPerTube;

    private array $palette;

    /** @var array<int, array<string, mixed>> */
    private array $cabinetIndex = [];

    /** @var array{sources: array<int, int>, by_branch: Collection, child_branches: Collection, fed_cabinets: Collection} */
    private array $topology;

    /** @var array<int, true> */
    private array $placed = [];

    /** @var array<int, true> */
    private array $visitedBranches = [];

    public function __construct(
        private readonly FiberPlanService $fiberPlans,
        private readonly BranchSourceResolver $sources,
    ) {}

    /**
     * @return array{sheet: array<string, mixed>, items: list<array<string, mixed>>, cabinets: array<int, array<string, mixed>>, plan: array<string, mixed>, schedule: list<array<string, mixed>>, stats: array<string, int>}
     */
    public function build(Project $project): array
    {
        $project->load([
            'odfs' => fn ($query) => $query->orderBy('name'),
            'branches.route',
            'cabinets' => fn ($query) => $query->withCount('houses'),
        ]);
        $this->items = [];
        $this->cabinetIndex = [];
        $this->plan = $this->fiberPlans->build($project);
        $this->colorStandard = $project->fiber_color_standard ?? 'telcordia';
        $this->fibersPerTube = (int) $this->plan['fibersPerTube'];
        $this->palette = FiberColorCode::paletteFor($this->colorStandard);

        $sections = $this->sections($project);
        $schedule = $this->schedule($project, $sections);
        $rightHeight = $this->rightColumnHeight(count($schedule));
        $blocks = array_map(fn (array $section) => $this->measureSection($section), $sections);
        [$columns, $diagramWidth, $diagramHeight] = $this->packColumns($blocks, $rightHeight);

        $contentWidth = max($diagramWidth, 120.0) + self::COLUMN_GAP + self::RIGHT_COLUMN;
        $contentHeight = max($diagramHeight, $rightHeight);
        $sheet = $this->chooseSheet($contentWidth, $contentHeight);

        $originX = self::FRAME_LEFT + self::PADDING;
        $originY = self::FRAME_OTHER + self::PADDING;
        foreach ($columns as $column) {
            foreach ($column['blocks'] as $placed) {
                $this->drawSection($placed['section'], $originX + $column['x'], $originY + $placed['y']);
            }
        }
        if ($sections === []) {
            $this->text($originX, $originY + 10, 'Projekat još nema ODF ni ODO ormariće za fiber šemu.', self::TEXT_NORMAL, 'FO-UPOZORENJE');
        }

        $rightX = $sheet['width'] - self::FRAME_OTHER - self::PADDING - self::RIGHT_COLUMN;
        $this->drawRightColumn($project, $rightX, $originY, $schedule, $sheet);
        $this->drawFrame($sheet);

        return [
            'sheet' => $sheet,
            'items' => $this->items,
            'cabinets' => $this->cabinetIndex,
            'plan' => $this->plan,
            'schedule' => $schedule,
            'stats' => [
                'odfs' => $project->odfs->count(),
                'cabinets' => $project->cabinets->count(),
                'drawn_cabinets' => count($this->cabinetIndex),
            ],
        ];
    }

    // ── Topology ──────────────────────────────────────────────────────────────

    /** One section per ODF (plus one for cabinets without an ODF). */
    private function sections(Project $project): array
    {
        $cabinets = $project->cabinets->keyBy('id');
        $branches = $project->branches->where('type', '!=', 'rov')->keyBy('id');
        $sources = $this->sources->resolve($branches, $cabinets);
        $this->topology = [
            'sources' => $sources,
            'by_branch' => $cabinets->groupBy('branch_id'),
            'child_branches' => $branches->filter(fn (NetworkBranch $branch) => isset($sources[$branch->id]))
                ->groupBy(fn (NetworkBranch $branch) => $sources[$branch->id]),
            'fed_cabinets' => $cabinets->filter(fn (Cabinet $cabinet) => ! $cabinet->branch_id && $cabinet->parent_cabinet_id && $cabinets->has($cabinet->parent_cabinet_id))
                ->groupBy('parent_cabinet_id'),
        ];
        $this->placed = [];
        $this->visitedBranches = [];

        $sections = [];
        foreach ($project->odfs as $odf) {
            // Roots: cables from this ODF, plus cables from a cabinet that could not be found.
            $roots = $branches->filter(fn (NetworkBranch $branch) => ! isset($sources[$branch->id])
                && ((int) $branch->odf_id === $odf->id || (! $branch->odf_id && $branch->route?->from_type === 'odf' && (int) $branch->route->from_id === $odf->id)));
            $primary = $roots->filter(fn (NetworkBranch $branch) => $this->isPrimary($branch));
            $rows = [];
            foreach ([...$this->sortBranches($primary), ...$this->sortBranches($roots->diff($primary))] as $branch) {
                $rows[] = $this->branchRow($branch);
            }
            $sections[] = ['odf' => $odf, 'rows' => array_values(array_filter($rows))];
        }

        // Cabinets no cable reached: a "no cable" row under their ODF. A cabinet whose parent
        // cabinet is still waiting comes in through that parent, so it is not listed twice.
        $odfIds = $project->odfs->pluck('id')->all();
        for ($pass = 0; $pass < 2; $pass++) {
            $remaining = $cabinets->reject(fn (Cabinet $cabinet) => $this->isPlaced($cabinet->id));
            if ($pass === 0) {
                $remaining = $remaining->reject(fn (Cabinet $cabinet) => $cabinet->parent_cabinet_id && $cabinets->has($cabinet->parent_cabinet_id) && ! $this->isPlaced((int) $cabinet->parent_cabinet_id));
            }
            foreach ($remaining->groupBy(fn (Cabinet $cabinet) => (int) $this->cabinetOdf($cabinet, $cabinets)) as $odfId => $group) {
                $index = array_search((int) $odfId, $odfIds, true);
                if ($index !== false) {
                    $sections[$index]['rows'][] = $this->cabinetRow('loose', $group);
                }
            }
        }
        $orphans = $cabinets->reject(fn (Cabinet $cabinet) => $this->isPlaced($cabinet->id));
        if ($orphans->isNotEmpty()) {
            $sections[] = ['odf' => null, 'rows' => [$this->cabinetRow('orphan', $orphans)]];
        }

        return $sections;
    }

    private function isPlaced(int $cabinetId): bool
    {
        return isset($this->placed[$cabinetId]);
    }

    private function isPrimary(NetworkBranch $branch): bool
    {
        return $branch->type === 'primary' || $branch->route?->route_type === 'feeder';
    }

    /** A cable (network branch) with the cabinets along it, or null if it is already drawn. */
    private function branchRow(NetworkBranch $branch): ?array
    {
        if (isset($this->visitedBranches[$branch->id])) {
            return null;
        }
        $this->visitedBranches[$branch->id] = true;
        $kind = $this->isPrimary($branch) ? 'primary' : (isset($this->topology['sources'][$branch->id]) ? 'child' : 'direct');

        return $this->cabinetRow($kind, $this->topology['by_branch']->get($branch->id, collect()), $branch);
    }

    private function cabinetRow(string $kind, Collection $cabinets, ?NetworkBranch $branch = null, ?Cabinet $source = null): array
    {
        $nodes = [];
        foreach ($this->sortCabinets($cabinets) as $cabinet) {
            if (! $this->isPlaced($cabinet->id)) {
                $nodes[] = $this->cabinetNode($cabinet);
            }
        }

        return ['kind' => $kind, 'branch' => $branch, 'source' => $source, 'cabinets' => $nodes];
    }

    /** A cabinet with the rows that leave from it: its own cables and the cabinets it feeds. */
    private function cabinetNode(Cabinet $cabinet): array
    {
        $this->placed[$cabinet->id] = true;
        $children = [];
        foreach ($this->sortBranches($this->topology['child_branches']->get($cabinet->id, collect())) as $branch) {
            $children[] = $this->branchRow($branch);
        }
        $fed = $this->topology['fed_cabinets']->get($cabinet->id, collect())->reject(fn (Cabinet $child) => $this->isPlaced($child->id));
        if ($fed->isNotEmpty()) {
            $children[] = $this->cabinetRow('fed', $fed, null, $cabinet);
        }

        return ['cabinet' => $cabinet, 'children' => array_values(array_filter($children))];
    }

    private function sortCabinets(Collection $cabinets): array
    {
        return $cabinets->sortBy(fn (Cabinet $cabinet) => sprintf('%08d|%s', (int) ($cabinet->branch_order ?? 99999999), $this->naturalKey($cabinet->name)))->values()->all();
    }

    private function sortBranches(Collection $branches): array
    {
        return $branches->sortBy(fn (NetworkBranch $branch) => sprintf('%08d|%s', (int) ($branch->sort_order ?? 99999999), $this->naturalKey($branch->name ?? '')))->values()->all();
    }

    private function cabinetOdf(Cabinet $cabinet, Collection $cabinets): ?int
    {
        $visited = [];
        while ($cabinet && ! isset($visited[$cabinet->id])) {
            if ($cabinet->odf_id) {
                return (int) $cabinet->odf_id;
            }
            $visited[$cabinet->id] = true;
            $cabinet = $cabinet->parent_cabinet_id ? $cabinets->get($cabinet->parent_cabinet_id) : null;
        }

        return null;
    }

    // ── Layout ────────────────────────────────────────────────────────────────

    /**
     * Assigns every row a y (top to bottom) and every cabinet an x, relative to the section.
     * Children of later cabinets come first, so drop lines never cross other rows.
     */
    private function measureSection(array $section): array
    {
        $rowIndex = 0;
        $maxX = 0.0;
        $layoutRow = null;
        $layoutRow = function (array &$row, float $startX) use (&$layoutRow, &$rowIndex, &$maxX): void {
            $row['index'] = $rowIndex++;
            $row['start_x'] = $startX;
            $x = $startX + self::FIRST_ODO_OFFSET;
            $previousHalf = 0.0;
            foreach ($row['cabinets'] as $i => &$node) {
                $width = $this->odoWidth($node['cabinet']);
                if ($i > 0) {
                    $x += max(self::ODO_GAP + $previousHalf + $width / 2, 48.0);
                }
                $node['x'] = $x;
                $node['w'] = $width;
                $previousHalf = $width / 2;
                $maxX = max($maxX, $x + 36.0);
            }
            unset($node);
            $labelEnd = $startX + 3 + $this->textWidth($this->rowLabel($row), self::TEXT_SMALL);
            $row['end_x'] = $row['cabinets'] === [] ? $startX + ($row['kind'] === 'primary' ? 110.0 : 70.0) : end($row['cabinets'])['x'];
            $maxX = max($maxX, $labelEnd, $row['end_x'] + 25);
            for ($i = count($row['cabinets']) - 1; $i >= 0; $i--) {
                foreach ($row['cabinets'][$i]['children'] as &$child) {
                    $layoutRow($child, $row['cabinets'][$i]['x'] + $row['cabinets'][$i]['w'] / 2);
                }
                unset($child);
            }
        };
        $startX = $section['odf'] ? self::ODF_WIDTH : 0.0;
        foreach ($section['rows'] as &$row) {
            $layoutRow($row, $startX);
        }
        unset($row);
        $rowCount = max($rowIndex, 1);
        $section['row_count'] = $rowCount;
        $section['width'] = max($maxX, self::ODF_WIDTH + 80);
        $section['height'] = 20.0 + $rowCount * self::ROW_PITCH + 6.0;

        return $section;
    }

    /** Packs the ODF sections into columns so the sheet keeps a sensible shape. */
    private function packColumns(array $blocks, float $rightHeight): array
    {
        if ($blocks === []) {
            return [[], 0.0, 0.0];
        }
        $best = null;
        $totalHeight = array_sum(array_column($blocks, 'height')) + self::SECTION_GAP * (count($blocks) - 1);
        $tallest = max(array_column($blocks, 'height'));
        for ($columnCount = 1; $columnCount <= count($blocks); $columnCount++) {
            $target = max($tallest, $totalHeight / $columnCount);
            $columns = [];
            $current = ['blocks' => [], 'height' => 0.0, 'width' => 0.0];
            foreach ($blocks as $block) {
                $nextHeight = $current['height'] + ($current['blocks'] ? self::SECTION_GAP : 0) + $block['height'];
                if ($current['blocks'] && $nextHeight > $target + 1 && count($columns) < $columnCount - 1) {
                    $columns[] = $current;
                    $current = ['blocks' => [], 'height' => 0.0, 'width' => 0.0];
                    $nextHeight = $block['height'];
                }
                $current['blocks'][] = ['section' => $block, 'y' => $current['height'] + ($current['blocks'] ? self::SECTION_GAP : 0)];
                $current['height'] = $nextHeight;
                $current['width'] = max($current['width'], $block['width']);
            }
            $columns[] = $current;
            $x = 0.0;
            foreach ($columns as &$column) {
                $column['x'] = $x;
                $x += $column['width'] + self::COLUMN_GAP;
            }
            unset($column);
            $width = $x - self::COLUMN_GAP;
            $height = max(array_column($columns, 'height'));
            $sheet = $this->chooseSheet($width + self::COLUMN_GAP + self::RIGHT_COLUMN, max($height, $rightHeight));
            $score = $sheet['width'] * $sheet['height'];
            if ($best === null || $score < $best[0] - 1) {
                $best = [$score, $columns, $width, $height];
            }
        }

        return [$best[1], $best[2], $best[3]];
    }

    /** Smallest ISO A-sheet (landscape or portrait) that holds the content; elongated beyond A0. */
    private function chooseSheet(float $contentWidth, float $contentHeight): array
    {
        $needWidth = $contentWidth + self::FRAME_LEFT + self::FRAME_OTHER + 2 * self::PADDING;
        $needHeight = $contentHeight + 2 * self::FRAME_OTHER + 2 * self::PADDING;
        foreach (self::SHEETS as $name => [$long, $short]) {
            foreach ([[$long, $short, 'položeno'], [$short, $long, 'uspravno']] as [$width, $height, $orientation]) {
                if ($needWidth <= $width && $needHeight <= $height) {
                    return ['name' => $name, 'width' => (float) $width, 'height' => (float) $height, 'orientation' => $orientation];
                }
            }
        }
        $width = max(1189.0, ceil($needWidth / 10) * 10);
        $height = max(841.0, ceil($needHeight / 10) * 10);

        return ['name' => 'A0+', 'width' => $width, 'height' => $height, 'orientation' => $width >= $height ? 'položeno' : 'uspravno'];
    }

    // ── Drawing the schematic ────────────────────────────────────────────────

    private function drawSection(array $section, float $left, float $top): void
    {
        $firstRowY = $top + 20.0;
        $rowY = fn (array $row) => $firstRowY + $row['index'] * self::ROW_PITCH;
        $lastY = $firstRowY + ($section['row_count'] - 1) * self::ROW_PITCH;

        if ($section['odf']) {
            /** @var Odf $odf */
            $odf = $section['odf'];
            $odfPlan = $this->plan['odfs'][$odf->id] ?? null;
            $boxTop = $top;
            $boxBottom = max($lastY + 22.0, $top + 60.0);
            $this->rect($left, $boxTop, self::ODF_WIDTH, $boxBottom - $boxTop, 'FO-ODF', '#eff6ff');
            $this->line($left, $boxTop + 15, $left + self::ODF_WIDTH, $boxTop + 15, 'FO-ODF');
            $this->text($left + self::ODF_WIDTH / 2, $boxTop + 6.5, 'ODF', self::TEXT_SMALL, 'FO-ODF', 'c', 'm');
            $this->text($left + self::ODF_WIDTH / 2, $boxTop + 11.5, $this->fit($odf->name, self::ODF_WIDTH - 4, self::TEXT_NORMAL), self::TEXT_NORMAL, 'FO-ODF', 'c', 'm', true);
            $facts = array_filter([
                $odf->port_count ? $odf->port_count.' portova' : null,
                $odf->fiber_capacity ? $odf->fiber_capacity.'F kapacitet' : null,
                $odfPlan ? 'zauzeto F1–F'.$odfPlan['usedTo'] : null,
                $odfPlan && $odfPlan['usedTo'] < $odfPlan['capacity'] ? 'rezerva F'.$odfPlan['reserveFrom'].'–F'.$odfPlan['reserveTo'] : null,
                'patch panel LC/APC',
            ]);
            $y = $boxTop + 21;
            foreach ($facts as $fact) {
                $this->text($left + 3, $y, $this->fit($fact, self::ODF_WIDTH - 5, self::TEXT_SMALL), self::TEXT_SMALL, 'FO-TEKST');
                $y += 4.5;
            }
        } else {
            $this->text($left, $top + 6, 'ODO BEZ ODF VEZE', self::TEXT_NORMAL, 'FO-UPOZORENJE', 'l', 'b', true);
            $this->text($left, $top + 11, 'Poveži ove ormariće na ODF da bi dobili vlakna.', self::TEXT_SMALL, 'FO-UPOZORENJE');
        }

        $drawRow = null;
        $drawRow = function (array $row, ?array $from) use (&$drawRow, $left, $rowY): void {
            $y = $rowY($row);
            $startX = $left + $row['start_x'];
            $layer = match ($row['kind']) {
                'primary' => 'FO-KABEL-PRIMARNI',
                'child', 'fed' => 'FO-KABEL-ODVOJAK',
                'loose', 'orphan' => 'FO-UPOZORENJE',
                default => 'FO-KABEL',
            };
            if ($from) {
                // Drop from the right edge of the feeding cabinet down to this row.
                $this->line($startX, $from['bottom'], $startX, $y, $layer);
            } elseif ($row['kind'] !== 'orphan') {
                $this->circle($startX, $y, 0.8, 'FO-ODF', true);
            }
            $endX = $left + $row['end_x'];
            $cursor = $startX;
            foreach ($row['cabinets'] as $node) {
                $cx = $left + $node['x'];
                $this->line($cursor, $y, $cx - $node['w'] / 2, $y, $layer);
                $cursor = $cx + $node['w'] / 2;
            }
            if ($row['cabinets'] === []) {
                $this->line($startX, $y, $endX, $y, $layer);
                if ($row['kind'] === 'primary') {
                    $this->line($endX, $y - 2.5, $endX, $y + 2.5, $layer);
                    $this->text($endX + 2, $y, 'nastavak kabla', self::TEXT_SMALL, 'FO-TEKST', 'l', 'm');
                } else {
                    $this->text($endX + 2, $y, 'bez ODO ormarića', self::TEXT_SMALL, 'FO-UPOZORENJE', 'l', 'm');
                }
            }
            $this->text($startX + 3, $y - 8.0, $this->rowLabel($row), self::TEXT_SMALL, $row['kind'] === 'loose' || $row['kind'] === 'orphan' ? 'FO-UPOZORENJE' : 'FO-TEKST', 'l', 'b');

            foreach ($row['cabinets'] as $node) {
                $box = $this->drawCabinet($node['cabinet'], $left + $node['x'], $y, $node['w'], $row);
                foreach ($node['children'] as $child) {
                    $drawRow($child, $box);
                }
            }
        };
        foreach ($section['rows'] as $row) {
            $drawRow($row, null);
        }
    }

    private function drawCabinet(Cabinet $cabinet, float $cx, float $cy, float $width, array $row): array
    {
        $allocation = $this->plan['allocations'][$cabinet->id] ?? null;
        $houses = (int) $cabinet->houses_count;
        $capacity = (int) $cabinet->capacity;
        $overfull = $houses > $capacity;
        $state = ! $allocation ? 'nofiber' : ($overfull ? 'full' : 'ok');
        $left = $cx - $width / 2;
        $top = $cy - self::ODO_HEIGHT / 2;
        $splitters = max(1, (int) $cabinet->splitter_count).'×1:'.max(1, (int) $cabinet->ports_per_splitter);
        $fiberText = $allocation ? ($allocation['from'] === $allocation['to'] ? 'F'.$allocation['from'] : 'F'.$allocation['from'].'–F'.$allocation['to']) : 'nema vlakna';
        $tube = $allocation ? FiberColorCode::describe($allocation['from'], $this->fibersPerTube, $this->colorStandard) : null;

        $this->items[] = [
            't' => 'odo', 'x' => $cx, 'y' => $cy, 'w' => $width, 'h' => self::ODO_HEIGHT,
            'name' => $cabinet->name, 'splitters' => $splitters, 'fibers' => $fiberText,
            'houses' => $houses.'/'.$capacity, 'state' => $state, 'cabinet_id' => $cabinet->id,
            'layer' => $state === 'ok' ? 'FO-ODO' : 'FO-UPOZORENJE',
        ];

        $textLayer = $state === 'ok' ? 'FO-TEKST' : 'FO-UPOZORENJE';
        $below = $top + self::ODO_HEIGHT;
        $this->text($left, $below + 4.0, ($tube ? 'T'.$tube['tube_number'].' · ' : '').$fiberText, self::TEXT_SMALL, $textLayer);
        $this->text($left, $below + 8.5, $houses.'/'.$capacity.' kuća'.($overfull ? ' – prepun' : ''), self::TEXT_SMALL, $textLayer);
        if ($allocation) {
            $x = $left;
            $this->swatch($x, $below + 10.0, $tube['tube'], false, 'cijev T'.$tube['tube_number']);
            $x += 4.2;
            foreach (range($allocation['from'], min($allocation['to'], $allocation['from'] + 7)) as $number) {
                $fiber = FiberColorCode::describe($number, $this->fibersPerTube, $this->colorStandard);
                $this->swatch($x, $below + 10.0, $fiber['fiber'], $fiber['traced'], 'F'.$number);
                $x += 3.0;
            }
        }

        $this->cabinetIndex[$cabinet->id] = [
            'id' => $cabinet->id, 'name' => $cabinet->name, 'x' => $cx, 'y' => $cy, 'state' => $state,
            'fibers' => $fiberText, 'tube' => $tube['tube_number'] ?? null, 'houses' => $houses, 'capacity' => $capacity,
            'splitters' => $splitters, 'cable' => $row['branch']?->name,
        ];

        return ['bottom' => $top + self::ODO_HEIGHT, 'right' => $cx + $width / 2];
    }

    private function rowLabel(array $row): string
    {
        $branch = $row['branch'];
        if (! $branch) {
            return match ($row['kind']) {
                'fed' => 'napaja se iz '.$row['source']?->name,
                'orphan' => 'bez ODF veze',
                default => 'bez kabla (nije povezan na krak)',
            };
        }
        $route = $branch->route;
        $parts = [mb_strtoupper($branch->name ?? 'Krak')];
        if ($route?->fiber_count) {
            $parts[] = $route->fiber_count.'F';
        }
        $length = $route?->fiber_length_m ?: $route?->duct_length_m;
        if ($length) {
            $parts[] = number_format((float) $length, 0, ',', '.').' m';
        }
        $allocated = collect($row['cabinets'])->map(fn ($node) => $this->plan['allocations'][$node['cabinet']->id] ?? null)->filter();
        if ($allocated->isNotEmpty()) {
            $parts[] = $allocated->min('from') === $allocated->max('to') ? 'F'.$allocated->min('from') : 'F'.$allocated->min('from').'–F'.$allocated->max('to');
        }

        return implode(' · ', $parts);
    }

    private function odoWidth(Cabinet $cabinet): float
    {
        return max(self::ODO_MIN_WIDTH, $this->textWidth($cabinet->name, self::TEXT_NORMAL) + 6);
    }

    // ── Legend, tables, title block, frame ───────────────────────────────────

    /** One row per cabinet, in drawing order: the fiber allocation table. */
    private function schedule(Project $project, array $sections): array
    {
        $rows = [];
        $walk = null;
        $walk = function (array $row, ?Odf $odf) use (&$walk, &$rows): void {
            foreach ($row['cabinets'] as $node) {
                $cabinet = $node['cabinet'];
                $allocation = $this->plan['allocations'][$cabinet->id] ?? null;
                $tube = $allocation ? FiberColorCode::describe($allocation['from'], $this->fibersPerTube, $this->colorStandard) : null;
                $rows[] = [
                    'cabinet_id' => $cabinet->id,
                    'odf' => $odf?->name ?? '—',
                    'cabinet' => $cabinet->name,
                    'cable' => $row['branch']?->name ?? $this->rowLabel($row),
                    'tube' => $tube ? 'T'.$tube['tube_number'] : '—',
                    'tube_color' => $tube['tube'] ?? null,
                    'fibers' => $allocation ? ($allocation['from'] === $allocation['to'] ? 'F'.$allocation['from'] : 'F'.$allocation['from'].'–F'.$allocation['to']) : '—',
                    'fiber_from' => $allocation['from'] ?? null,
                    'fiber_to' => $allocation['to'] ?? null,
                    'splitters' => max(1, (int) $cabinet->splitter_count).'×1:'.max(1, (int) $cabinet->ports_per_splitter),
                    'houses' => (int) $cabinet->houses_count.'/'.(int) $cabinet->capacity,
                    'problem' => ! $allocation || $cabinet->houses_count > $cabinet->capacity,
                ];
                foreach ($node['children'] as $child) {
                    $walk($child, $odf);
                }
            }
        };
        foreach ($sections as $section) {
            foreach ($section['rows'] as $row) {
                $walk($row, $section['odf']);
            }
        }

        return $rows;
    }

    private function rightColumnHeight(int $scheduleRows): float
    {
        return 46.0 + 10 + 54.0 + 10 + (12.0 + 6.0 * max(1, $scheduleRows)) + 10 + 62.0;
    }

    private function drawRightColumn(Project $project, float $x, float $y, array $schedule, array $sheet): void
    {
        $w = self::RIGHT_COLUMN;

        // Legend.
        $this->tableHeader($x, $y, $w, 'LEGENDA');
        $ly = $y + 12;
        $this->rect($x + 4, $ly - 3, 12, 6, 'FO-ODF', '#eff6ff');
        $this->text($x + 20, $ly, 'ODF – optički distributivni okvir (OLT strana)', self::TEXT_SMALL, 'FO-TEKST', 'l', 'm');
        $ly += 8;
        $this->rect($x + 4, $ly - 3, 12, 6, 'FO-ODO', '#f0fdf4');
        $this->text($x + 20, $ly, 'ODO – distributivni ormarić (splitteri 1:N)', self::TEXT_SMALL, 'FO-TEKST', 'l', 'm');
        $ly += 8;
        $this->line($x + 4, $ly, $x + 16, $ly, 'FO-KABEL');
        $this->text($x + 20, $ly, 'kabel iz ODF-a  ·', self::TEXT_SMALL, 'FO-TEKST', 'l', 'm');
        $this->line($x + 62, $ly, $x + 74, $ly, 'FO-KABEL-ODVOJAK');
        $this->text($x + 78, $ly, 'odvojak iz ODO-a  ·', self::TEXT_SMALL, 'FO-TEKST', 'l', 'm');
        $this->line($x + 124, $ly, $x + 136, $ly, 'FO-KABEL-PRIMARNI');
        $this->text($x + 140, $ly, 'primarni kabel', self::TEXT_SMALL, 'FO-TEKST', 'l', 'm');
        $ly += 8;
        $this->rect($x + 4, $ly - 3, 12, 6, 'FO-UPOZORENJE', '#fef2f2');
        $this->text($x + 20, $ly, 'crveno: ODO bez vlakna, prepun ili bez ODF veze  ·  T = cijev, F = vlakno', self::TEXT_SMALL, 'FO-UPOZORENJE', 'l', 'm');
        $this->rect($x, $y, $w, 46, 'FO-TABELA');
        $y += 46 + 10;

        // Colour code.
        $standardLabel = $this->colorStandard === 'din_vde' ? 'DIN VDE 0888' : 'TIA-598-C';
        $this->tableHeader($x, $y, $w, 'KOD BOJA VLAKANA I CIJEVI · '.$standardLabel.' · '.($project->fiber_layout ?? '6x24'));
        foreach (array_values($this->palette) as $i => $color) {
            $cx = $x + 4 + intdiv($i, 6) * ($w / 2);
            $cy = $y + 12 + ($i % 6) * 6;
            $this->swatch($cx, $cy - 1.6, $color, false, (string) ($i + 1));
            $this->text($cx + 6, $cy, ($i + 1).'  '.$color['name'], self::TEXT_SMALL, 'FO-TEKST', 'l', 'm');
        }
        $this->text($x + 4, $y + 50, 'Vlakna 13–24 u cijevi: ista boja s crnim prstenom (označeno kosom crtom).', self::TEXT_SMALL, 'FO-TEKST', 'l', 'b');
        $this->rect($x, $y, $w, 54, 'FO-TABELA');
        $y += 54 + 10;

        // Fiber allocation schedule.
        $columns = [['ODF', 26], ['ODO', 30], ['KABEL / KRAK', 56], ['CIJEV', 14], ['VLAKNA', 26], ['SPL.', 16], ['KUĆE', 22]];
        $this->tableHeader($x, $y, $w, 'TABELA DODJELE VLAKANA');
        $hy = $y + 8;
        $cx = $x;
        foreach ($columns as [$label, $width]) {
            $this->text($cx + 1.5, $hy + 2.5, $label, self::TEXT_SMALL, 'FO-TEKST', 'l', 'm', true);
            $cx += $width;
        }
        $this->line($x, $hy + 5, $x + $w, $hy + 5, 'FO-TABELA');
        $ry = $hy + 5;
        foreach ($schedule as $row) {
            $values = [$row['odf'], $row['cabinet'], $row['cable'], $row['tube'], $row['fibers'], $row['splitters'], $row['houses']];
            $cx = $x;
            foreach ($columns as $i => [, $width]) {
                $this->text($cx + 1.5, $ry + 3, $this->fit((string) $values[$i], $width - 2.5, self::TEXT_SMALL), self::TEXT_SMALL, $row['problem'] ? 'FO-UPOZORENJE' : 'FO-TEKST', 'l', 'm');
                $cx += $width;
            }
            $ry += 6;
            $this->line($x, $ry, $x + $w, $ry, 'FO-TABELA');
        }
        if ($schedule === []) {
            $this->text($x + 2, $ry + 3, 'Nema ODO ormarića.', self::TEXT_SMALL, 'FO-TEKST', 'l', 'm');
            $ry += 6;
        }
        $cx = $x;
        foreach ($columns as [, $width]) {
            $cx += $width;
            if ($cx < $x + $w - 0.5) {
                $this->line($cx, $hy, $cx, $ry, 'FO-TABELA');
            }
        }
        $this->rect($x, $y, $w, $ry - $y, 'FO-TABELA');

        $this->drawTitleBlock($project, $sheet);
    }

    /** ISO 7200 style title block in the bottom-right corner of the frame. */
    private function drawTitleBlock(Project $project, array $sheet): void
    {
        $w = self::RIGHT_COLUMN;
        $h = 62.0;
        $x = $sheet['width'] - self::FRAME_OTHER - $w;
        $y = $sheet['height'] - self::FRAME_OTHER - $h;
        $revision = $project->fiberSchemaVersions()->latest()->value('label');
        $profile = $this->plan['profile'];
        $this->rect($x, $y, $w, $h, 'FO-ZAGLAVLJE', '#ffffff');

        $field = function (float $fx, float $fy, float $fw, float $fh, string $label, string $value, float $height = self::TEXT_SMALL, bool $bold = false): void {
            $this->rect($fx, $fy, $fw, $fh, 'FO-ZAGLAVLJE');
            $this->text($fx + 1.5, $fy + 3.2, $label, 1.8, 'FO-TABELA');
            $this->text($fx + 1.5, $fy + $fh - 2.2, $this->fit($value, $fw - 3, $height), $height, 'FO-ZAGLAVLJE', 'l', 'b', $bold);
        };
        $field($x, $y, $w, 16, 'NAZIV CRTEŽA', 'FIBER ŠEMA – ODN (bez mjerila)', self::TEXT_LARGE, true);
        $field($x, $y + 16, $w * 0.62, 12, 'PROJEKAT', trim($project->name.' · '.($project->code ?? '')), self::TEXT_NORMAL, true);
        $field($x + $w * 0.62, $y + 16, $w * 0.38, 12, 'LOKACIJA', (string) ($project->location ?: '—'));
        $field($x, $y + 28, $w * 0.62, 11, 'INVESTITOR', (string) ($project->investor ?: '—'));
        $field($x + $w * 0.62, $y + 28, $w * 0.38, 11, 'STANDARD', $profile['standard'].' · '.$profile['label']);
        $field($x, $y + 39, $w * 0.25, 11, 'REVIZIJA', $revision ?: 'radna');
        $field($x + $w * 0.25, $y + 39, $w * 0.25, 11, 'STATUS', $project->fiber_schema_locked ? 'odobreno' : 'radna verzija');
        $field($x + $w * 0.5, $y + 39, $w * 0.25, 11, 'DATUM', now()->format('d.m.Y.'));
        $field($x + $w * 0.75, $y + 39, $w * 0.25, 11, 'LIST', $sheet['name'].' · 1/1');
        $field($x, $y + 50, $w * 0.5, 12, 'PLAN ID', 'PLAN ID '.$this->plan['signature']);
        $field($x + $w * 0.5, $y + 50, $w * 0.25, 12, 'MJERILO', 'NTS');
        $field($x + $w * 0.75, $y + 50, $w * 0.25, 12, 'JEDINICE', 'mm');
    }

    private function drawFrame(array $sheet): void
    {
        $this->rect(0, 0, $sheet['width'], $sheet['height'], 'FO-OKVIR');
        $this->rect(self::FRAME_LEFT, self::FRAME_OTHER, $sheet['width'] - self::FRAME_LEFT - self::FRAME_OTHER, $sheet['height'] - 2 * self::FRAME_OTHER, 'FO-OKVIR');
        // Centring marks (ISO 5457).
        $midX = $sheet['width'] / 2;
        $midY = $sheet['height'] / 2;
        $this->line($midX, 0, $midX, self::FRAME_OTHER, 'FO-OKVIR');
        $this->line($midX, $sheet['height'] - self::FRAME_OTHER, $midX, $sheet['height'], 'FO-OKVIR');
        $this->line(0, $midY, self::FRAME_LEFT, $midY, 'FO-OKVIR');
        $this->line($sheet['width'] - self::FRAME_OTHER, $midY, $sheet['width'], $midY, 'FO-OKVIR');
    }

    private function tableHeader(float $x, float $y, float $w, string $title): void
    {
        $this->rect($x, $y, $w, 8, 'FO-TABELA', '#f1f5f9');
        $this->text($x + 2, $y + 4, $title, self::TEXT_SMALL, 'FO-TEKST', 'l', 'm', true);
    }

    // ── Primitives ───────────────────────────────────────────────────────────

    private function line(float $x1, float $y1, float $x2, float $y2, string $layer): void
    {
        $this->items[] = ['t' => 'line', 'x1' => $x1, 'y1' => $y1, 'x2' => $x2, 'y2' => $y2, 'layer' => $layer];
    }

    private function rect(float $x, float $y, float $w, float $h, string $layer, ?string $fill = null): void
    {
        $this->items[] = ['t' => 'rect', 'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h, 'layer' => $layer, 'fill' => $fill];
    }

    private function circle(float $x, float $y, float $r, string $layer, bool $fill = false): void
    {
        $this->items[] = ['t' => 'circle', 'x' => $x, 'y' => $y, 'r' => $r, 'layer' => $layer, 'fill' => $fill];
    }

    /** $ha: l|c|r, $va: b (baseline)|m (middle)|t (top). */
    private function text(float $x, float $y, string $text, float $height, string $layer, string $ha = 'l', string $va = 'b', bool $bold = false): void
    {
        $this->items[] = ['t' => 'text', 'x' => $x, 'y' => $y, 's' => $text, 'h' => $height, 'ha' => $ha, 'va' => $va, 'layer' => $layer, 'bold' => $bold];
    }

    /** A filled colour square for a fiber or tube; traced fibers (13–24) get a black diagonal. */
    private function swatch(float $x, float $y, array $color, bool $traced, string $title): void
    {
        $this->items[] = [
            't' => 'swatch', 'x' => $x, 'y' => $y, 'size' => 2.4, 'hex' => $color['hex'], 'border' => $color['border'],
            'aci' => self::ACI_BY_COLOR[$color['english']] ?? 7, 'traced' => $traced, 'title' => $title, 'layer' => 'FO-VLAKNA',
        ];
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /** Width of Arial-like text at a CAD text height (cap height). */
    public function textWidth(string $text, float $height): float
    {
        return mb_strlen($text) * $height * 0.78;
    }

    private function fit(string $text, float $width, float $height): string
    {
        $max = max(3, (int) floor($width / ($height * 0.78)));

        return mb_strlen($text) > $max ? mb_substr($text, 0, $max - 1).'…' : $text;
    }

    private function naturalKey(string $value): string
    {
        return (string) preg_replace_callback('/\d+/', fn (array $match) => str_pad($match[0], 8, '0', STR_PAD_LEFT), mb_strtolower($value));
    }
}
