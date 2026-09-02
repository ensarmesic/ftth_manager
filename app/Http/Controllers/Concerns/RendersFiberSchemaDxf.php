<?php

namespace App\Http\Controllers\Concerns;

use App\Models\NetworkBranch;
use App\Models\Project;
use App\Services\FiberPlanService;
use App\Support\FiberColorCode;
use Illuminate\Support\Collection;

trait RendersFiberSchemaDxf
{
    public function exportFiberSchema(Project $project, FiberPlanService $fiberPlanService)
    {
        $project->load([
            'odfs',
            'branches' => fn ($q) => $q->with([
                'route',
                'cabinets' => fn ($q2) => $q2->withCount('houses')->orderBy('branch_order')->orderBy('name'),
            ])->orderBy('sort_order'),
        ]);

        // ── Dodjela vlakana ───────────────────────────────────────────────────
        $fiberPlan = $fiberPlanService->build($project);
        $fiberAlloc = $fiberPlan['allocations'];
        $revision = $project->fiberSchemaVersions()->with('user:id,name')->latest()->first();
        $fibersPerTube = str_ends_with($project->fiber_layout ?? '6x24', 'x12') ? 12 : 24;
        $dxfColorByName = ['Blue' => 5, 'Orange' => 30, 'Green' => 3, 'Brown' => 32, 'Slate' => 8, 'White' => 7, 'Red' => 1, 'Black' => 250, 'Yellow' => 2, 'Violet' => 6, 'Rose' => 210, 'Aqua' => 4];
        $fiberDxfColors = collect(FiberColorCode::paletteFor($project->fiber_color_standard ?? 'telcordia'))->map(fn (array $color) => $dxfColorByName[$color['english']])->values()->all();

        // ── Konstante ─────────────────────────────────────────────────────────
        $OX = 280.0;
        $OY = 200.0;
        $OW = 30.0;
        $OH = 36.0;
        $OG = 115.0;
        $BG = 23.0;
        $CG = 22.0;
        $FP = 0.8;
        $TM = 40.0;
        $CW = 5.8;
        $CH = 9.6;
        $FCD = 47.0;
        $FCD_CAB = 25.0;
        $CHILD_BG = 22.0; // razmak između dijete-grana u child zoni

        // ── DXF header ───────────────────────────────────────────────────────
        $L = [
            '0', 'SECTION', '2', 'HEADER',
            '9', '$ACADVER', '1', 'AC1009',
            '9', '$LTSCALE', '40', '1.0',
            '0', 'ENDSEC',
            '0', 'SECTION', '2', 'TABLES',
            '0', 'TABLE', '2', 'LTYPE', '70', '1',
            '0', 'LTYPE', '2', 'CONTINUOUS', '70', '64', '3', '', '72', '65', '73', '0', '40', '0.0',
            '0', 'ENDTAB',
            '0', 'TABLE', '2', 'LAYER', '70', '7',
            '0', 'LAYER', '2', '0', '70', '64', '62', '7', '6', 'CONTINUOUS',
            '0', 'LAYER', '2', 'FTTH_ODF', '70', '64', '62', '5', '6', 'CONTINUOUS',
            '0', 'LAYER', '2', 'FTTH_PRIMARY', '70', '64', '62', '5', '6', 'CONTINUOUS',
            '0', 'LAYER', '2', 'FTTH_SECONDARY', '70', '64', '62', '6', '6', 'CONTINUOUS',
            '0', 'LAYER', '2', 'FTTH_CABINETS', '70', '64', '62', '7', '6', 'CONTINUOUS',
            '0', 'LAYER', '2', 'FTTH_LABELS', '70', '64', '62', '1', '6', 'CONTINUOUS',
            '0', 'LAYER', '2', 'FTTH_FIBER_COLORS', '70', '64', '62', '7', '6', 'CONTINUOUS',
            '0', 'ENDTAB',
            '0', 'TABLE', '2', 'STYLE', '70', '1',
            '0', 'STYLE', '2', 'FTTH', '70', '0', '40', '0.0', '41', '0.8', '50', '0.0', '71', '0', '42', '3.0',
            '3', 'romans.shx', '4', '',
            '0', 'ENDTAB',
            '0', 'ENDSEC',
            '0', 'SECTION', '2', 'ENTITIES',
        ];

        // ODF Y pozicije
        $odfCY = [];
        foreach ($project->odfs as $oi => $odf) {
            $odfCY[$odf->id] = $OY + $oi * $OG;
        }
        $allCY = array_values($odfCY) ?: [$OY];
        $trunkT = max($allCY) + $OH / 2 + $TM;
        $trunkB = min($allCY) - $OH / 2 - $TM;

        // Primarni trup (vertikalne plave linije)
        $primBranches = $project->branches->where('type', 'primary')->sortBy('sort_order')->values();
        $primCount = max($primBranches->count(), 2);
        $primXs = [];
        for ($pi = 0; $pi < $primCount; $pi++) {
            $px = $OX - 1.4 + $pi * 1.8;
            $primXs[] = $px;
            array_push($L, ...$this->dxfLine($px, $trunkB, $px, $trunkT, 'FTTH_PRIMARY', ($pi % 2 === 0) ? 5 : 6));
        }
        foreach ($primBranches as $pi => $pb) {
            $px = $primXs[$pi] ?? ($OX - 1.4 + $pi * 1.8);
            $spec = $pb->route?->fiber_count ? ' '.$pb->route->fiber_count.'F' : '';
            if ($pb->route?->duct_length_m) {
                $spec .= '/'.(int) $pb->route->duct_length_m.'m';
            }
            array_push($L, ...$this->dxfText($px + 2.0, $trunkT + 2.0, $pb->name.$spec, 'FTTH_PRIMARY', 1, 2.2));
        }

        // Pratimo gdje je svaki ormarić nacrtan: [x, tapY, boxTop, boxBot, side]
        $cabPos = [];

        foreach ($project->odfs as $odf) {
            $cx = $OX;
            $cy = $odfCY[$odf->id];

            // ── FAZA 1: Grane koje dolaze direktno iz ODF-a ──────────────────
            // Uključujemo i grane bez odf_id (koje nisu eksplicitno dodijeljene)
            $directBranches = $project->branches
                ->where('type', 'secondary')
                ->filter(fn ($b) => $b->route !== null
                    && $b->route->from_type !== 'cabinet'
                    && ($b->odf_id === $odf->id
                        || ($b->odf_id === null && $b->route->from_type === 'odf')))
                ->sortBy('sort_order')
                ->values();

            $sideCnt = [1 => 0, -1 => 0];
            $sideSlt = [1 => 0, -1 => 0];
            foreach ($directBranches as $i => $_) {
                $sideCnt[($i % 2 === 0) ? 1 : -1]++;
            }

            // Dinamički OH: ODF visina prati raspon grana (max offset ± BG/2 sa marginom)
            $maxSide = max($sideCnt[1], $sideCnt[-1], 1);
            $maxOffset = (($maxSide - 1) / 2.0) * $BG;
            $dynOH = max($OH, $maxOffset * 2.0 + 10.0);
            $odfL = $cx - $OW / 2;
            $odfR = $cx + $OW / 2;

            // ODF kutija (dinamička visina)
            array_push($L, ...$this->dxfRect($odfL, $cy - $dynOH / 2, $odfR, $cy + $dynOH / 2, 'FTTH_ODF', 5));
            array_push($L, ...$this->dxfText($odfL + 2, $cy + 5, $odf->name, 'FTTH_ODF', 7, 2.8));
            array_push($L, ...$this->dxfText($odfL + 2, $cy + 1, 'OPTICAL DISTRIBUTION FRAME', 'FTTH_ODF', 5, 1.6));
            array_push($L, ...$this->dxfText($odfL + 2, $cy - 2.5, ($odf->port_count ?? '?').' PORTOVA / '.($odf->fiber_capacity ?? '?').'F', 'FTTH_ODF', 5, 1.6));
            array_push($L, ...$this->dxfText($odfL + 2, $cy - 5.5, 'PATCH PANEL - LC/APC', 'FTTH_ODF', 4, 1.4));

            $phaseOneMinY = $cy; // prati najmanji boxBot svih direktnih grana

            foreach ($directBranches as $idx => $branch) {
                $side = ($idx % 2 === 0) ? 1 : -1;
                $slot = $sideSlt[$side]++;
                $maxS = max(1, $sideCnt[$side]);
                $bY = $cy - ($slot - ($maxS - 1) / 2.0) * $BG;

                $portX = ($side > 0) ? $odfR : $odfL;
                $edgeX = $portX + $side * 7.0;

                $tw = 2.8;
                $th = 2.4;
                $tl = ($side > 0) ? $portX : $portX - $tw;
                array_push($L, ...$this->dxfRect($tl, $bY - $th / 2, $tl + $tw, $bY + $th / 2, 'FTTH_ODF', 5));
                array_push($L, ...$this->dxfLine($portX, $bY, $edgeX, $bY, 'FTTH_SECONDARY', 6));

                $this->schemaLabel($L, $branch, $edgeX, $bY, $side);
                $this->schemaCabinets($L, $branch->cabinets, $portX, $edgeX, $bY, $side, $FCD, $CG, $CW, $CH, $FP, $fiberAlloc, $cabPos, $phaseOneMinY, $fibersPerTube, $fiberDxfColors);
            }

            // ── FAZA 2: Dijete-grane u zasebnoj zoni ispod svih Faze 1 grana ──
            $childBranches = $project->branches
                ->where('type', 'secondary')
                ->filter(fn ($b) => $b->route?->from_type === 'cabinet')
                ->sortBy('sort_order')
                ->values();

            // Child zona počinje 15 jedinica ispod najnižeg boxBot iz Faze 1
            $childBaseY = $phaseOneMinY - 15.0;
            $childIdx = 0;

            foreach ($childBranches as $branch) {
                $srcId = $branch->route->from_id ?? null;
                if (! $srcId || ! isset($cabPos[$srcId])) {
                    continue;
                }

                $src = $cabPos[$srcId];
                $side = $src['side'];
                $srcX = $src['x'];

                // Sekvencijalni Y u child zoni — svaka grana $CHILD_BG ispod prethodne
                $bY = $childBaseY - ($childIdx * $CHILD_BG);
                $edgeX = $srcX + $side * 12.0;
                $childIdx++;

                // L-konektor: vertikalno od dna izvora do bY, horizontalno do edgeX
                array_push($L, ...$this->dxfLine($srcX, $src['boxBot'], $srcX, $bY, 'FTTH_SECONDARY', 6));
                array_push($L, ...$this->dxfLine($srcX, $bY, $edgeX, $bY, 'FTTH_SECONDARY', 6));

                $this->schemaLabel($L, $branch, $edgeX, $bY, $side);

                $dummyMin = PHP_FLOAT_MAX;
                $this->schemaCabinets($L, $branch->cabinets, $srcX, $edgeX, $bY, $side, $FCD_CAB, $CG, $CW, $CH, $FP, $fiberAlloc, $cabPos, $dummyMin, $fibersPerTube, $fiberDxfColors);
            }
        }

        $cabinetXs = collect($cabPos)->pluck('x');
        $cabinetBottoms = collect($cabPos)->pluck('boxBot');
        $titleRight = max((float) ($cabinetXs->max() ?? $OX), $OX + $OW / 2) + 25;
        $titleLeft = $titleRight - 105;
        $titleBottom = min((float) ($cabinetBottoms->min() ?? $trunkB), $trunkB) - 34;
        $titleTop = $titleBottom + 22;
        array_push($L, ...$this->dxfRect($titleLeft, $titleBottom, $titleRight, $titleTop, 'FTTH_LABELS', 7));
        array_push($L, ...$this->dxfLine($titleLeft, $titleBottom + 7, $titleRight, $titleBottom + 7, 'FTTH_LABELS', 7));
        array_push($L, ...$this->dxfLine($titleLeft, $titleBottom + 14, $titleRight, $titleBottom + 14, 'FTTH_LABELS', 7));
        array_push($L, ...$this->dxfText($titleLeft + 2, $titleTop - 4, 'FTTH FIBER PLAN / ODN SHEMA - NTS / PLAN ID '.$fiberPlan['signature'], 'FTTH_LABELS', 7, 1.8));
        array_push($L, ...$this->dxfText($titleLeft + 2, $titleBottom + 10, 'PROJEKAT: '.($project->code ?: $project->name).' | REV: '.($revision?->label ?? 'RADNA'), 'FTTH_LABELS', 7, 1.35));
        array_push($L, ...$this->dxfText($titleLeft + 2, $titleBottom + 3, 'STANDARD: '.$fiberPlan['profile']['standard'].' / '.$fiberPlan['profile']['label'].' | '.strtoupper($project->fiber_schema_locked ? 'ODOBRENO' : 'RADNA VERZIJA').' | '.now()->format('d.m.Y'), 'FTTH_LABELS', 7, 1.2));

        array_push($L, '0', 'ENDSEC', '0', 'EOF');

        $exportCode = str($project->code ?: $project->name)->slug()->value() ?: 'projekat-'.$project->id;

        return response(implode("\r\n", $L)."\r\n", 200, [
            'Content-Type' => 'application/dxf',
            'Content-Disposition' => 'attachment; filename="'.$exportCode.'-fiber-schema.dxf"',
            'X-Fiber-Plan-Signature' => $fiberPlan['signature'],
        ]);
    }

    // Oznaka grane iznad linije — desno ako side=+1, lijevo-poravnato ako side=-1
    private function schemaLabel(array &$L, NetworkBranch $branch, float $edgeX, float $bY, int $side): void
    {
        $name = $branch->name ?? '';
        $specs = '';
        if ($branch->route) {
            $specs = 'KABEL '.($branch->route->fiber_count ?? '?').'F';
            $length = $branch->route->fiber_length_m ?: $branch->route->duct_length_m;
            if ($length) {
                $specs .= ' / '.(int) $length.'m';
            }
        }

        if ($side > 0) {
            array_push($L, ...$this->dxfText($edgeX + 2, $bY + 3.2, $name, 'FTTH_LABELS', 1, 1.8));
            if ($specs !== '') {
                array_push($L, ...$this->dxfText($edgeX + 2, $bY + 1.2, $specs, 'FTTH_LABELS', 6, 1.3));
            }
        } else {
            array_push($L, ...$this->dxfTextRight($edgeX - 2, $bY + 3.2, $name, 'FTTH_LABELS', 1, 1.8));
            if ($specs !== '') {
                array_push($L, ...$this->dxfTextRight($edgeX - 2, $bY + 1.2, $specs, 'FTTH_LABELS', 6, 1.3));
            }
        }
    }

    // Crta ormarice uz horizontalnu bus-liniju
    private function schemaCabinets(
        array &$L, Collection $cabinets, float $portX, float $edgeX,
        float $bY, int $side, float $fcd, float $cg,
        float $cw, float $ch, float $fp,
        array $fiberAlloc, array &$cabPos, float &$minBoxBot, int $fibersPerTube = 24, array $fiberDxfColors = []
    ): void {
        $cabs = $cabinets
            ->sortBy(fn ($c) => sprintf('%06d|%s', (int) ($c->branch_order ?? 0), (string) $c->name))
            ->values();
        $nCab = $cabs->count();
        if ($nCab === 0) {
            return;
        }

        $stackH = ($nCab - 1) * $fp;
        $busT = $bY + $stackH / 2 + 1.5;
        $busB = $bY - $stackH / 2 - 2.4;
        array_push($L, ...$this->dxfLine($edgeX, $busB, $edgeX, $busT + 0.9, 'FTTH_SECONDARY', 6));

        foreach ($cabs as $ci => $cabinet) {
            $x = $portX + $side * ($fcd + $ci * $cg);
            $tapY = $bY - ($ci - $nCab / 2.0 + 0.5) * $fp;
            $boxT = $tapY - 3.4;
            $boxB = $boxT - $ch;
            $boxL = $x - $cw / 2;
            $boxR = $x + $cw / 2;

            // Horizontalna linija vlakna + tap krug + oznaka vlakna
            array_push($L, ...$this->dxfLine($edgeX, $tapY, $x, $tapY, 'FTTH_SECONDARY', 6));
            array_push($L, ...$this->dxfCircle($x, $tapY, 0.55, 'FTTH_SECONDARY', 6));

            $fa = $fiberAlloc[$cabinet->id] ?? null;
            if ($fa) {
                $fiberDxfColors = $fiberDxfColors ?: [5, 30, 3, 32, 8, 7, 1, 250, 2, 6, 210, 4];
                $fiberCount = $fa['to'] - $fa['from'] + 1;
                foreach (range($fa['from'], $fa['to']) as $fiberIndex => $fiberNumber) {
                    $position = (($fiberNumber - 1) % $fibersPerTube) + 1;
                    $offset = ($fiberIndex - ($fiberCount - 1) / 2) * 0.24;
                    $dxfColor = $fiberDxfColors[($position - 1) % 12];
                    array_push($L, ...$this->dxfLine($edgeX, $tapY + $offset, $x, $tapY + $offset, 'FTTH_FIBER_COLORS', $dxfColor));
                }
            }
            $fl = $fa
                ? ($fa['from'] === $fa['to'] ? (string) $fa['from'] : $fa['from'].'-'.$fa['to'])
                : '?';
            array_push($L, ...$this->dxfText($x - 1.8, $tapY + 1.5, $fl, 'FTTH_LABELS', 6, 1.2));

            // Vertikalna kapljica tap → vrh kutije
            array_push($L, ...$this->dxfLine($x, $tapY, $x, $boxT, 'FTTH_SECONDARY', 6));

            // Kutija ormarića + naziv unutar (90°, h=0.9 stane u CH=9.6)
            array_push($L, ...$this->dxfRect($boxL, $boxB, $boxR, $boxT, 'FTTH_CABINETS', 7));
            array_push($L, ...$this->dxfTextRotated($boxL + 1.0, $boxB + 0.5, $cabinet->name, 'FTTH_CABINETS', 7, 0.9, 90.0));

            $cabPos[$cabinet->id] = ['x' => $x, 'tapY' => $tapY, 'boxTop' => $boxT, 'boxBot' => $boxB, 'side' => $side];
            $minBoxBot = min($minBoxBot, $boxB);
        }
    }
}
