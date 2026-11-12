<?php

namespace App\Services;

use App\Support\DuctSurvey\DuctSurveyDescription as D;

/**
 * Turns the surveyor's walk (points in recording order, each describing what
 * lies in the trench) into a network GRAPH:
 *
 *  - main trench points are joined in walking order; a jump, a change of the
 *    tube set or a return after a detour re-attaches to the nearest point that
 *    carries the same tubes, so branches and lines surveyed later connect
 *    where they physically belong;
 *  - spurs ("dolaz na stub/u ZO") hang off the line and end at their object;
 *    markers (izvod, spojnica, slinga) sit on the line;
 *  - every tube colour is then followed through the graph from its ODF (or
 *    the ZO it starts in) to the closures, boxes and ends it reaches.
 *
 * Pure: planar Gauss-Krüger metres in, arrays out. No database access.
 */
class DuctSurveyInterpreter
{
    /** Points along a trench are 2-10 m apart; more than this is a jump. */
    private const GAP_M = 25.0;

    private const ATTACH_M = 25.0;

    /** A new line starting this close to an ODF starts in that ODF. */
    private const ODF_START_M = 10.0;

    /** Two points this close are the same spot. */
    private const REUSE_M = 0.3;

    /** A marker this close to the line lies on the line. */
    private const ON_LINE_M = 3.0;

    /** Max distance from the line to a ZO/box (a "slinga do ZO" can be 25 m). */
    private const OBJECT_M = 40.0;

    /** A tube missing on the incoming edge is searched for this far around. */
    private const COLOR_LINK_M = 10.0;

    private const LEAF_JOIN_M = 1.5;

    /** An ordinary step between two neighbouring trench points. */
    private const STEP_M = 8.0;

    private const ALL = '*';

    /** @var array<int,array<string,mixed>> */
    private array $nodes = [];

    /** @var list<array<string,mixed>> */
    private array $edges = [];

    /** @var array<int,array<int,int>> node => [neighbour => edge index] */
    private array $adj = [];

    /** @var list<array{point_no:?int,message:string}> */
    private array $warnings = [];

    /** @var array<int,array{node:int,color:string,from_zo:?array}> */
    private array $starts = [];

    /** @var list<array{point_no:?int,message:string}> informative remarks (not problems) */
    private array $notes = [];

    /** @var array<int,bool> node => its part of the network contains an ODF */
    private array $partHasOdf = [];

    private ?int $lastMain = null;

    private int $nextNode = 0;

    private int $nextEdge = 0;

    /**
     * @param  list<array{point_no:int,x:float,y:float,z?:float,code:string}>  $points
     */
    public function interpret(array $points): array
    {
        $this->nodes = $this->edges = $this->adj = $this->warnings = $this->starts = $this->notes = $this->partHasOdf = [];
        $this->lastMain = null;
        $this->nextNode = $this->nextEdge = 0;

        usort($points, fn (array $a, array $b) => $a['point_no'] <=> $b['point_no']);
        $points = array_map(fn (array $p) => $p + ['parsed' => D::parse($p['code'])], $points);
        $skipped = array_values(array_filter($points, fn (array $p) => $p['parsed']['ignored']));
        $points = array_values(array_filter($points, fn (array $p) => ! $p['parsed']['ignored']));
        $points = $this->fixZigzags($points);

        $side = [];
        foreach ($points as $p) {
            if ($p['parsed']['type'] === 'odf') {
                $this->odfNode($p);

                continue;
            }
            if ($p['parsed']['role'] === D::ROLE_MAIN) {
                // Side points first: a tube re-entering the trench here may come
                // from a marker just recorded (e.g. "izvod žute ide preko puta").
                $this->flushSide($side);
                $side = [];
                $this->addMain($p);

                continue;
            }
            $side[] = $p;
        }
        $this->flushSide($side);
        $this->attachLonelyOdfs();
        $this->joinLeaves();
        $this->removeRedundantJumps();
        $this->pruneBackSteps();
        $this->resolveNamedOrigins();
        $this->restrictClosureColors();
        $this->dropOrphanTubes();
        $this->warnDetachedParts();

        // A tube that starts right at a closure starts IN that closure.
        $starts = array_values(array_filter($this->starts, fn (array $s) => isset($this->nodes[$s['node']]) && isset($this->colorsOf($s['node'])[$s['color']]) && ! array_filter(
            array_keys($this->adj[$s['node']]),
            fn (int $id) => in_array($this->nodes[$id]['kind'], ['zo', 'odf'], true),
        )));
        $secondaries = $this->secondaries();

        return [
            'nodes' => $this->nodes,
            'edges' => $this->edges,
            'flows' => $this->flows(),
            'secondaries' => $secondaries,
            'starts' => $starts,
            'warnings' => $this->warnings,
            'checks' => $this->surveyChecks($points, $skipped, $secondaries, $starts),
        ];
    }

    // ------------------------------------------------------------------
    // Survey checks: what the engineer should look at or ask the surveyor
    // ------------------------------------------------------------------

    /**
     * @return list<array{level:string,point_no:?int,message:string}> level: greska | upozorenje | info
     */
    private function surveyChecks(array $points, array $skipped, array $secondaries, array $starts): array
    {
        $checks = [];
        $add = function (string $level, ?int $pointNo, string $message) use (&$checks): void {
            $checks[] = ['level' => $level, 'point_no' => $pointNo, 'message' => $message];
        };

        foreach ($this->warnings as $w) {
            $add('upozorenje', $w['point_no'], $w['message']);
        }
        foreach ($this->notes as $note) {
            $add('info', $note['point_no'], $note['message']);
        }
        $surveyedOdfs = [];
        foreach ($this->nodes as $n) {
            if ($n['kind'] === 'odf') {
                $surveyedOdfs[$n['odf_no']] = true;
            }
        }
        $unverifiable = [];
        foreach ($secondaries as $list) {
            foreach ($list as $zo) {
                if ($zo['reached']) {
                    continue;
                }
                $odf = $this->nodes[$zo['node']]['zo']['odf'];
                // Only a closure whose ODF is in the survey can be checked.
                if ($odf !== null && isset($surveyedOdfs[$odf])) {
                    $add('greska', $this->nodes[$zo['node']]['points'][0], 'ZO '.$zo['label'].' nije povezan cijevima s ODF-om '.$odf.'.');
                } else {
                    $unverifiable[] = 'ZO '.$zo['label'];
                }
            }
        }
        if ($unverifiable !== []) {
            $add('info', null, implode(', ', $unverifiable).': ODF tih ZO-a nije u snimku (ili ga nema u nazivu) – povezanost se ne može provjeriti.');
        }

        // ZO names: krak and ODF written, no duplicates, no skipped numbers.
        $seen = [];
        foreach ($this->nodes as $n) {
            if ($n['kind'] !== 'zo') {
                continue;
            }
            $zo = $n['zo'];
            $missing = array_keys(array_filter(['krak (S)' => $zo['secondary'] === null, 'ODF' => $zo['odf'] === null]));
            if ($missing !== []) {
                $add('info', $n['points'][0], '"'.$n['code'].'" nema '.implode(' ni ', $missing).' u nazivu – piši npr. "ZO 1-S2-ODF 3".');
            }
            $key = ($zo['odf'] ?? '?').'|'.($zo['secondary'] ?? '-').'|'.$zo['label'];
            if (isset($seen[$key]) && $this->dist($n, $this->nodes[$seen[$key]]) > 5.0) {
                $add('upozorenje', $n['points'][0], 'ZO '.$zo['label'].' se pojavljuje dva puta (i tačka '.$this->nodes[$seen[$key]]['points'][0].').');
            }
            $seen[$key] ??= $n['id'];
        }
        foreach ($secondaries as $key => $list) {
            $top = array_values(array_unique(array_map(fn ($zo) => (int) explode('.', $zo['label'])[0], $list)));
            sort($top);
            $gaps = array_diff(range(1, max($top ?: [1])), $top);
            if ($gaps !== []) {
                [$odf, $s] = explode('|', $key);
                $add('info', null, 'ODF '.$odf.($s !== '-' ? ' / S'.$s : '').': nema ZO '.implode(', ', $gaps).' (snimljeni su ZO '.implode(', ', $top).').');
            }
        }

        foreach ($skipped as $p) {
            $add('info', $p['point_no'], 'Tačka je označena kao pogrešna i preskočena: "'.$p['code'].'".');
        }
        // Same slip repeated on a run of points: one line per description.
        $byCode = ['count' => [], 'note' => []];
        foreach ($points as $p) {
            if ($p['parsed']['count_missing']) {
                $byCode['count'][$p['code']][] = $p['point_no'];
            }
            if ($p['parsed']['type'] === 'note' && $p['parsed']['ducts'] === [] && ! $p['parsed']['continues']) {
                $byCode['note'][$p['code']][] = $p['point_no'];
            }
        }
        foreach ($byCode['count'] as $code => $numbers) {
            $add('info', $numbers[0], $this->pointList($numbers).': "x" bez broja u opisu "'.$code.'".');
        }
        foreach ($byCode['note'] as $code => $numbers) {
            $add('info', $numbers[0], $this->pointList($numbers).': opis nije prepoznat (samo napomena) "'.$code.'".');
        }

        // Deleted points: numbers missing between two consecutive recorded points.
        $numbers = array_column([...$points, ...$skipped], 'point_no');
        sort($numbers);
        for ($i = 1; $i < count($numbers); $i++) {
            $gap = $numbers[$i] - $numbers[$i - 1];
            if ($gap > 1 && $gap <= 20) {
                [$from, $to] = [$numbers[$i - 1] + 1, $numbers[$i] - 1];
                $add('info', $from, 'Tačk'.($from === $to ? 'a '.$from.' ne postoji' : 'e '.$from.'–'.$to.' ne postoje').' u fajlu (obrisane?) – provjeri da li je linija tu ispravno spojena.');
            }
        }

        foreach ($starts as $s) {
            // Without an ODF in this part, every tube "starts" here: covered by one note.
            if (! ($this->partHasOdf[$s['node']] ?? true)) {
                continue;
            }
            $add('upozorenje', $this->nodes[$s['node']]['points'][0], 'Cijev '.$this->tubeName($s['color']).' počinje usred linije – nije poznato odakle dolazi (napiši "iz ZO …").');
        }

        // A tube that just stops: no ZO, box, izvod or slinga at its end.
        foreach ($this->flows() as $flow) {
            foreach ($flow['ends'] as $end) {
                $n = $this->nodes[$end['node']];
                if ($n['kind'] === 'line' && ! $this->hasMarkerNear($end['node'])) {
                    $add('info', $n['points'][0], 'Cijev '.$this->tubeName($flow['color']).' ovdje završava bez oznake (izvod, slinga, ZO) – nastavlja li se u sljedećem snimku?');
                }
            }
        }

        $order = ['greska' => 0, 'upozorenje' => 1, 'info' => 2];
        usort($checks, fn ($a, $b) => [$order[$a['level']], $a['point_no'] ?? 0] <=> [$order[$b['level']], $b['point_no'] ?? 0]);

        return $checks;
    }

    private function tubeName(string $color): string
    {
        return D::tubeLabel($color);
    }

    /** "Tačka 2400" / "Tačke 2400–2413" (consecutive) / "Tačke 2400, 2405, 2410". */
    private function pointList(array $numbers): string
    {
        sort($numbers);
        if (count($numbers) === 1) {
            return 'Tačka '.$numbers[0];
        }
        $consecutive = end($numbers) - $numbers[0] === count($numbers) - 1;

        return 'Tačke '.($consecutive ? $numbers[0].'–'.end($numbers) : implode(', ', array_slice($numbers, 0, 8)).(count($numbers) > 8 ? ' …' : ''));
    }

    private function hasMarkerNear(int $id): bool
    {
        if (str_contains(D::normalize($this->nodes[$id]['code']), 'nastavak') || str_contains(D::normalize($this->nodes[$id]['code']), 'slinga')) {
            return true;
        }
        foreach (array_keys($this->adj[$id]) as $other) {
            if (in_array($this->nodes[$other]['kind'], ['izvod', 'slinga', 'zo', 'box', 'odf'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * "Rov + 14/10 Zuta iz ZO 2-S2": a tube that starts mid-line but names the
     * closure it comes from runs from that closure along the trench to here.
     */
    private function resolveNamedOrigins(): void
    {
        foreach ($this->starts as $index => $start) {
            $spec = $start['from_zo'] ?? null;
            if ($spec === null || ! isset($this->nodes[$start['node']])) {
                continue;
            }
            $closure = null;
            foreach ($this->nodes as $n) {
                if ($n['kind'] === 'zo' && $n['zo']['label'] === $spec['label']
                    && ($spec['secondary'] === null || $n['zo']['secondary'] === $spec['secondary'])
                    && ($spec['odf'] === null || $n['zo']['odf'] === $spec['odf'])
                    && ($closure === null || $this->dist($n, $this->nodes[$start['node']]) < $this->dist($this->nodes[$closure], $this->nodes[$start['node']]))) {
                    $closure = $n['id'];
                }
            }
            if ($closure === null) {
                $this->warn($this->nodes[$start['node']]['points'][0], 'U opisu piše "iz ZO '.$spec['label'].'", ali taj ZO nije snimljen.');

                continue;
            }
            $path = $this->trenchPath($start['node'], $this->linePointsOf($closure), 500.0);
            if ($path === null) {
                $this->warn($this->nodes[$start['node']]['points'][0], 'Cijev '.$start['color'].' iz ZO '.$spec['label'].': nema rova do tog ZO-a.');

                continue;
            }
            foreach ($path as $edgeIndex) {
                if (is_array($this->edges[$edgeIndex]['ducts'])) {
                    $this->edges[$edgeIndex]['ducts'][$start['color']] ??= 1;
                }
            }
            foreach ($this->adj[$closure] as $attach) {
                if (is_array($this->edges[$attach]['ducts'])) {
                    $this->edges[$attach]['ducts'][$start['color']] ??= 1;
                }
            }
            unset($this->starts[$index]);
        }
    }

    /** Edge indexes of the shortest trench path from a node to any of $targets (within $limit m). */
    private function trenchPath(int $from, array $targets, float $limit): ?array
    {
        $goal = array_fill_keys($targets, true);
        $dist = [$from => 0.0];
        $via = [];
        $queue = new \SplPriorityQueue;
        $queue->insert($from, 0.0);
        while (! $queue->isEmpty()) {
            $id = $queue->extract();
            if (isset($goal[$id])) {
                $edges = [];
                for ($at = $id; isset($via[$at]); $at = $via[$at][0]) {
                    $edges[] = $via[$at][1];
                }

                return $edges;
            }
            foreach ($this->adj[$id] ?? [] as $next => $index) {
                if (! in_array($this->edges[$index]['kind'], ['trench', 'link', 'join', 'crossing'], true)) {
                    continue;
                }
                $d = $dist[$id] + $this->dist($this->nodes[$id], $this->nodes[$next]);
                if ($d <= $limit && $d < ($dist[$next] ?? INF)) {
                    $dist[$next] = $d;
                    $via[$next] = [$id, $index];
                    $queue->insert($next, -$d);
                }
            }
        }

        return null;
    }

    // ------------------------------------------------------------------
    // Walking the survey
    // ------------------------------------------------------------------

    private function addMain(array $p): void
    {
        $ducts = $p['parsed']['ducts'];
        $reuse = $this->nearest($p, fn (array $n) => $n['kind'] === 'line', self::REUSE_M);
        if ($reuse !== null) {
            $this->nodes[$reuse]['points'][] = $p['point_no'];
            $this->nodes[$reuse]['ducts'] = $this->mergeDucts($this->nodes[$reuse]['ducts'], $ducts);
            if ($this->lastMain !== null && $this->lastMain !== $reuse && ! isset($this->adj[$this->lastMain][$reuse])
                && $this->dist($this->nodes[$this->lastMain], $p) <= self::GAP_M) {
                $this->connect($this->lastMain, $reuse, $this->intersect($this->colorsOf($this->lastMain), $ducts), 'trench');
            }
            $this->lastMain = $reuse;

            return;
        }

        $id = $this->createNode($p, 'line');
        $primary = $this->choosePredecessor($id, $p);
        $edgeDucts = [];
        if ($primary !== null) {
            $edgeDucts = $this->nodes[$primary]['kind'] === 'odf'
                ? $ducts
                : $this->intersect($this->colorsOf($primary), $ducts);
            $this->connect($primary, $id, $edgeDucts, 'trench', $p['parsed']['alkaten']);
        }

        // Every tube must come from somewhere: one missing on the incoming edge
        // continues from the nearest point that carries it, or starts here.
        foreach (array_diff_key($ducts, $edgeDucts) as $color => $count) {
            $source = $this->nearest(
                $p,
                fn (array $n) => $n['id'] !== $id && $n['id'] !== $primary
                    && ! in_array($n['kind'], ['zo', 'box'], true)
                    && isset($this->colorsOf($n['id'])[$color]),
                self::COLOR_LINK_M,
            );
            if ($source !== null) {
                $this->connect($source, $id, [$color => $count], 'link');
            } else {
                $this->starts[] = ['node' => $id, 'color' => $color, 'from_zo' => $p['parsed']['from_zo']];
            }
        }
        $this->lastMain = $id;
    }

    private function choosePredecessor(int $id, array $p): ?int
    {
        $ducts = $p['parsed']['ducts'];
        $last = $this->lastMain;
        $lastDist = $last !== null ? $this->dist($this->nodes[$last], $p) : INF;
        $isLine = fn (array $n) => $n['kind'] === 'line' && $n['id'] !== $id;

        if ($last !== null && $lastDist <= self::GAP_M
            && ($ducts === [] || $this->overlap($this->colorsOf($last), $ducts) > 0 || $this->colorsOf($last) === [])) {
            // Back from a detour (spur to a ZO, a short side run): the line goes on
            // from the point it left, which is much closer than the detour's end.
            if ($lastDist > 3.0) {
                $closer = $this->nearest(
                    $p,
                    fn (array $n) => $isLine($n) && $n['id'] !== $last && $this->overlap($this->colorsOf($n['id']), $ducts) > 0,
                    $lastDist * 0.5,
                );
                if ($closer !== null) {
                    // The surveyor walked a stretch out and back: the run just
                    // recorded still ends here, so do not leave it dangling.
                    if ($this->lineDegree($last) === 1 && $lastDist <= 10.0
                        && D::normalize($this->nodes[$last]['code']) === D::normalize($p['code'])) {
                        $this->connect($last, $id, $this->intersect($this->colorsOf($last), $ducts), 'trench');
                    }

                    return $closer;
                }
            }

            return $last;
        }

        $odf = $this->nearest($p, fn (array $n) => $n['kind'] === 'odf', self::ODF_START_M);
        if ($odf !== null) {
            return $odf;
        }

        $best = null;
        $bestScore = [0, -INF];
        foreach ($this->nodes as $n) {
            if (! $isLine($n)) {
                continue;
            }
            $d = $this->dist($n, $p);
            if ($d > self::ATTACH_M) {
                continue;
            }
            $score = [$this->overlap($this->colorsOf($n['id']), $ducts), -$d];
            if ($score[0] > 0 && $score > $bestScore) {
                [$best, $bestScore] = [$n['id'], $score];
            }
        }
        if ($best !== null) {
            return $best;
        }
        // Nothing carries these tubes: the trench still goes on from the last
        // point only if it is the ordinary next step, not a jump to a new line.
        if ($last !== null && $lastDist <= self::STEP_M) {
            return $last;
        }

        return $this->nearest($p, $isLine, self::LEAF_JOIN_M);
    }

    /** @param list<array> $chain side points between two main points */
    private function flushSide(array $chain): void
    {
        if ($chain === []) {
            return;
        }
        $isObject = fn (array $p) => in_array($p['parsed']['type'], ['zo', 'box'], true);
        $ordered = [...array_filter($chain, fn ($p) => ! $isObject($p)), ...array_filter($chain, $isObject)];
        $isLine = fn (array $n) => $n['kind'] === 'line';
        $prev = null;

        foreach ($ordered as $p) {
            $parsed = $p['parsed'];
            $type = $parsed['type'];

            if ($type === 'line') { // spur
                $id = $this->createNode($p, 'spur');
                if ($prev !== null && $this->nodes[$prev]['kind'] === 'spur' && $this->dist($this->nodes[$prev], $p) <= self::GAP_M) {
                    $this->connect($prev, $id, $parsed['ducts'], 'spur');
                } elseif (($q = $this->nearest($p, $isLine, self::ATTACH_M)) !== null) {
                    $this->connect($q, $id, $parsed['ducts'], 'spur');
                } else {
                    $this->warn($p['point_no'], 'Odvojak nema blizu liniju na koju se veže.');
                }
                $prev = $id;

                continue;
            }

            if ($isObject($p)) {
                // The same closure recorded with a second point (two corners): one ZO.
                if ($parsed['zo'] !== null) {
                    $same = $this->nearest($p, fn (array $n) => $n['kind'] === 'zo' && $n['zo'] == $parsed['zo'], 5.0);
                    if ($same !== null) {
                        $this->nodes[$same]['points'][] = $p['point_no'];

                        continue;
                    }
                }
                $id = $this->createNode($p, $type, $parsed['zo'] !== null ? ['zo' => $parsed['zo']] : []);
                // Reached by its own spur ("dolaz u ZO") or by a "slinga do ZO";
                // otherwise it stands beside the trench.
                $viaPrev = $prev !== null
                    && in_array($this->nodes[$prev]['kind'], ['spur', 'slinga'], true)
                    && $this->dist($this->nodes[$prev], $p) <= self::OBJECT_M;
                $target = $viaPrev
                    ? $prev
                    : ($this->nearest($p, $isLine, self::OBJECT_M)
                        ?? ($prev !== null && $this->dist($this->nodes[$prev], $p) <= self::OBJECT_M ? $prev : null)
                        ?? $this->nearest($p, fn (array $n) => $n['id'] !== $id, self::OBJECT_M));
                if ($target === null) {
                    $this->warn($p['point_no'], ($type === 'zo' ? 'ZO' : 'Box').' nije blizu nijedne linije.');
                } else {
                    $this->connect($target, $id, self::ALL, 'attach');
                }

                continue;
            }

            // Marker: izvod, spojnica, slinga, bušenje, šaht, napomena.
            $id = $this->createNode($p, $type, ['continues' => $parsed['continues'], 'terminates' => $parsed['terminates']]);
            if (($q = $this->nearest($p, $isLine, self::ON_LINE_M)) !== null) {
                // On the line: it belongs to the tubes it names (izvod plave ends the blue).
                $this->connect($q, $id, $this->intersect($this->colorsOf($q), $parsed['ducts']), 'attach');
            } elseif ($prev !== null && $this->dist($this->nodes[$prev], $p) <= self::GAP_M) {
                // e.g. "Izvod žute ide preko puta" twice: the tube crosses the road.
                $ducts = $parsed['ducts'] ?: $this->colorsOf($prev);
                $this->connect($prev, $id, $ducts, $type === 'boring' || $this->nodes[$prev]['kind'] === 'izvod' ? 'crossing' : 'link');
            } elseif (($q = $this->nearest($p, fn (array $n) => $n['id'] !== $id, self::ATTACH_M)) !== null) {
                $this->connect($q, $id, $parsed['ducts'], 'link');
            } else {
                $this->warn($p['point_no'], 'Oznaka "'.$p['code'].'" nije blizu nijedne linije.');
            }
            $prev = $id;
        }
    }

    private function odfNode(array $p): void
    {
        $no = $p['parsed']['odf_no'];
        foreach ($this->nodes as $id => $n) {
            if ($n['kind'] === 'odf' && $n['odf_no'] === $no && $this->dist($n, $p) <= 15.0) {
                $count = count($n['points']);
                $this->nodes[$id]['x'] = ($n['x'] * $count + $p['x']) / ($count + 1);
                $this->nodes[$id]['y'] = ($n['y'] * $count + $p['y']) / ($count + 1);
                $this->nodes[$id]['points'][] = $p['point_no'];

                return;
            }
        }
        $this->createNode($p, 'odf', ['odf_no' => $no]);
    }

    private function attachLonelyOdfs(): void
    {
        foreach ($this->nodes as $id => $n) {
            if ($n['kind'] !== 'odf' || ($this->adj[$id] ?? []) !== []) {
                continue;
            }
            $q = $this->nearest($n, fn (array $m) => $m['kind'] === 'line', 15.0);
            if ($q === null) {
                $this->warn($n['points'][0], 'ODF '.$n['odf_no'].' nije spojen ni na jednu liniju.');

                continue;
            }
            $this->connect($q, $id, self::ALL, 'attach');
        }
    }

    /** Close tiny gaps between a dead end and a line surveyed another time. */
    private function joinLeaves(): void
    {
        foreach ($this->nodes as $id => $n) {
            // Only true dead ends of a line: not markers already sitting on the
            // line, not spurs that end up a pole.
            $only = $this->adj[$id] === [] ? null : $this->edges[reset($this->adj[$id])];
            if (! in_array($n['kind'], ['line', 'izvod', 'boring'], true) || count($this->adj[$id]) > 1
                || ($only !== null && $only['kind'] === 'attach')) {
                continue;
            }
            $colors = $this->colorsOf($id);
            $q = $this->nearest($n, fn (array $m) => $m['id'] !== $id
                && ! isset($this->adj[$id][$m['id']])
                && in_array($m['kind'], ['line', 'spur', 'izvod', 'boring', 'splice'], true)
                && ($colors === [] || $this->colorsOf($m['id']) === [] || $this->overlap($colors, $this->colorsOf($m['id'])) > 0),
                self::LEAF_JOIN_M);
            if ($q !== null) {
                $this->connect($id, $q, $this->intersect($colors, $this->colorsOf($q)) ?: $colors, 'join');
            }
        }
    }

    /**
     * Points the surveyor deleted leave a straight jump between their
     * neighbours; when that stretch was surveyed again point by point, the jump
     * is a false shortcut that closes a loop. Drop it (longest jumps first).
     */
    private function removeRedundantJumps(): void
    {
        $jumps = [];
        foreach ($this->edges as $i => $e) {
            $length = $this->dist($this->nodes[$e['a']], $this->nodes[$e['b']]);
            if ($e['kind'] === 'trench' && $length >= 8.0) {
                $jumps[$i] = $length;
            }
        }
        arsort($jumps);
        foreach ($jumps as $i => $length) {
            if (! isset($this->edges[$i])) {
                continue;
            }
            $e = $this->edges[$i];
            $alternative = $this->boundedTrenchDistance($e['a'], $e['b'], $i, $length * 1.6, is_array($e['ducts']) ? $e['ducts'] : []);
            if ($alternative !== null) {
                $this->removeEdge($i);
            }
        }
    }

    /** Shortest trench distance a→b avoiding one edge, along edges carrying $ducts; null beyond $limit. */
    private function boundedTrenchDistance(int $from, int $to, int $skipEdge, float $limit, array $ducts): ?float
    {
        $dist = [$from => 0.0];
        $queue = new \SplPriorityQueue;
        $queue->insert($from, 0.0);
        while (! $queue->isEmpty()) {
            $id = $queue->extract();
            if ($id === $to) {
                return $dist[$to];
            }
            foreach ($this->adj[$id] ?? [] as $next => $index) {
                $e = $this->edges[$index];
                if ($index === $skipEdge || ! in_array($e['kind'], ['trench', 'link', 'join'], true)
                    || ($ducts !== [] && (! is_array($e['ducts']) || $this->overlap($e['ducts'], $ducts) === 0))) {
                    continue;
                }
                $d = $dist[$id] + $this->dist($this->nodes[$id], $this->nodes[$next]);
                if ($d <= $limit && $d < ($dist[$next] ?? INF)) {
                    $dist[$next] = $d;
                    $queue->insert($next, -$d);
                }
            }
        }

        return null;
    }

    /**
     * The surveyor sometimes steps a metre or two back and records a point
     * before going on in another direction: a tiny dead end hanging off a
     * junction. Fold it into the point it hangs from.
     */
    private function pruneBackSteps(): void
    {
        $folded = [];
        foreach ($this->nodes as $id => $n) {
            if (isset($folded[$id]) || $n['kind'] !== 'line' || count($this->adj[$id]) !== 1) {
                continue;
            }
            $q = array_key_first($this->adj[$id]);
            if ($this->nodes[$q]['kind'] !== 'line' || count($this->adj[$q]) < 3 || $this->dist($n, $this->nodes[$q]) > 3.0
                || array_diff_key($this->colorsOf($id), $this->colorsOf($q)) !== []) {
                continue;
            }
            $this->removeEdge($this->adj[$id][$q]);
            $this->nodes[$q]['points'] = [...$this->nodes[$q]['points'], ...$n['points']];
            unset($this->nodes[$id], $this->adj[$id]);
            $folded[$id] = true;
            if ($this->lastMain === $id) {
                $this->lastMain = $q;
            }
        }
    }

    /**
     * A ZO standing beside a multi-tube trench is not fed by every tube in it:
     * keep the tubes that also serve the other closures of its secondary
     * (ZO 1-S3 beside a plava+žuta+crvena trench is on the plava, like ZO 2-S3).
     */
    private function restrictClosureColors(): void
    {
        $candidates = [];
        foreach ($this->nodes as $id => $n) {
            if ($n['kind'] !== 'zo') {
                continue;
            }
            foreach ($this->adj[$id] as $neighbour => $index) {
                if ($this->edges[$index]['ducts'] === self::ALL) {
                    $colors = array_diff_key($this->colorsOf($neighbour), ['md' => true]);
                    $candidates[$id] = ['edge' => $index, 'colors' => $colors];
                }
            }
        }
        foreach ($candidates as $id => $candidate) {
            if (count($candidate['colors']) < 2) {
                continue;
            }
            $zo = $this->nodes[$id]['zo'];
            $siblingColors = [];
            foreach ($candidates as $otherId => $other) {
                $otherZo = $this->nodes[$otherId]['zo'];
                if ($otherId !== $id && $otherZo['odf'] === $zo['odf'] && $otherZo['secondary'] === $zo['secondary']) {
                    $siblingColors += $other['colors'];
                }
            }
            $kept = array_intersect_key($candidate['colors'], $siblingColors);
            if ($kept !== [] && count($kept) < count($candidate['colors'])) {
                $this->edges[$candidate['edge']]['ducts'] = $kept;
            }
        }
    }

    /** Every part of the network not connected to the rest is reported once. */
    private function warnDetachedParts(): void
    {
        $adj = array_map(fn (array $row) => array_map(fn () => 0.0, $row), $this->adj);
        $seen = [];
        $parts = [];
        foreach (array_keys($this->nodes) as $id) {
            if (isset($seen[$id])) {
                continue;
            }
            $part = $this->component($adj, $id);
            $seen += array_fill_keys($part, true);
            $parts[] = $part;
        }
        if (count($parts) < 2) {
            return;
        }
        usort($parts, fn ($a, $b) => count($b) <=> count($a));
        foreach (array_slice($parts, 1) as $part) {
            $numbers = array_merge(...array_map(fn (int $id) => $this->nodes[$id]['points'], $part));
            $this->warn(min($numbers), sprintf('Dio mreže (tačke %d–%d) nije povezan s ostatkom.', min($numbers), max($numbers)));
        }
    }

    /**
     * A tube that leads to no ODF (and, for a coloured 14/10, to no ZO either)
     * cannot exist in the network: it is a slip in the field description
     * (e.g. "MD" written on points where there is none). Report it, don't draw it.
     */
    private function dropOrphanTubes(): void
    {
        $colors = [];
        foreach ($this->edges as $e) {
            if (is_array($e['ducts'])) {
                $colors += array_fill_keys(array_keys($e['ducts']), true);
            }
        }
        // Which parts of the network contain an ODF at all? Only there can a tube
        // that reaches no ODF be judged a slip: a day file without the ODF (it was
        // surveyed another day) keeps all its tubes.
        $whole = array_map(fn (array $row) => array_map(fn () => 0.0, $row), $this->adj);
        $partWithOdf = [];
        $warnedParts = [];
        foreach (array_keys($this->nodes) as $id) {
            if (isset($partWithOdf[$id])) {
                continue;
            }
            $part = $this->component($whole, $id);
            $hasOdf = array_filter($part, fn (int $n) => $this->nodes[$n]['kind'] === 'odf') !== [];
            $partWithOdf += array_fill_keys($part, $hasOdf ? true : min($part));
            $this->partHasOdf += array_fill_keys($part, $hasOdf);
        }

        foreach (array_keys($colors) as $color) {
            $adj = $this->colorAdjacency($color);
            $seen = [];
            foreach (array_keys($adj) as $start) {
                if (isset($seen[$start])) {
                    continue;
                }
                $component = $this->component($adj, $start);
                $seen += array_fill_keys($component, true);
                $rooted = array_filter($component, fn (int $id) => $this->nodes[$id]['kind'] === 'odf'
                    || ($color !== 'md' && $this->nodes[$id]['kind'] === 'zo'));
                if ($rooted !== []) {
                    continue;
                }
                $part = $partWithOdf[$component[0]];
                if ($part !== true) {
                    if (! isset($warnedParts[$part])) {
                        $warnedParts[$part] = true;
                        $numbers = array_merge(...array_map(fn (int $id) => $this->nodes[$id]['points'], array_keys(array_filter($partWithOdf, fn ($p) => $p === $part))));
                        $this->notes[] = ['point_no' => min($numbers), 'message' => sprintf(
                            'Dio mreže (tačke %d–%d) nema ODF u snimku – cijevi su ucrtane, ali se ne može provjeriti odakle dolaze (ODF je možda u drugom snimku).',
                            min($numbers), max($numbers),
                        )];
                    }

                    continue;
                }
                $inside = array_fill_keys($component, true);
                foreach ($this->edges as $index => $e) {
                    if (is_array($e['ducts']) && isset($inside[$e['a']], $inside[$e['b']])) {
                        unset($this->edges[$index]['ducts'][$color]);
                    }
                }
                foreach ($component as $id) {
                    unset($this->nodes[$id]['ducts'][$color]);
                }
                $numbers = array_merge(...array_map(fn (int $id) => $this->nodes[$id]['points'], $component));
                $this->warn(min($numbers), sprintf(
                    'Cijev %s (tačke %d–%d) ne vodi ni do ODF-a ni do ZO-a – vjerovatno greška u opisu, nije ucrtana.',
                    $this->tubeName($color), min($numbers), max($numbers),
                ));
            }
        }
    }

    /**
     * The surveyor sometimes records two neighbouring points in swapped order;
     * joining them as recorded draws a zigzag. Swap such pairs on main runs.
     */
    private function fixZigzags(array $points): array
    {
        $main = array_keys(array_filter($points, fn ($p) => $p['parsed']['role'] === D::ROLE_MAIN));
        for ($k = 1; $k + 2 < count($main); $k++) {
            [$a, $b, $c, $d] = [$points[$main[$k - 1]], $points[$main[$k]], $points[$main[$k + 1]], $points[$main[$k + 2]]];
            if (D::normalize($b['code']) !== D::normalize($c['code'])) {
                continue;
            }
            $asRecorded = $this->dist($a, $b) + $this->dist($b, $c) + $this->dist($c, $d);
            $swapped = $this->dist($a, $c) + $this->dist($c, $b) + $this->dist($b, $d);
            if ($swapped < $asRecorded * 0.7 && $asRecorded - $swapped > 3.0) {
                [$points[$main[$k]], $points[$main[$k + 1]]] = [$c, $b];
            }
        }

        return $points;
    }

    // ------------------------------------------------------------------
    // Following the tubes
    // ------------------------------------------------------------------

    /**
     * For every colour: connected runs, each rooted at its ODF (or the ZO it
     * starts in), listing the closures, boxes and ends in order along the run.
     */
    private function flows(): array
    {
        $colors = [];
        foreach ($this->edges as $e) {
            if (is_array($e['ducts'])) {
                $colors += array_fill_keys(array_keys($e['ducts']), true);
            }
        }
        $flows = [];
        foreach (array_keys($colors) as $color) {
            $adj = $this->colorAdjacency($color);
            $seen = [];
            foreach (array_keys($adj) as $start) {
                if (isset($seen[$start])) {
                    continue;
                }
                $component = $this->component($adj, $start);
                $seen += array_fill_keys($component, true);
                if (count($component) < 2) {
                    continue;
                }
                $root = $this->rootOf($component);
                foreach ($this->splitAtOdf($adj, $component, $root) as $run) {
                    $flows[] = $this->flow($color, $adj, $run, $root);
                }
            }
        }

        return $flows;
    }

    /**
     * Tubes of one colour leaving an ODF in different directions are different
     * tubes: split the run at the ODF into one run per direction.
     *
     * @return list<list<int>>
     */
    private function splitAtOdf(array $adj, array $component, int $root): array
    {
        if ($this->nodes[$root]['kind'] !== 'odf') {
            return [$component];
        }
        $without = $adj;
        foreach (array_keys($without[$root]) as $next) {
            unset($without[$next][$root]);
        }
        unset($without[$root]);
        $runs = [];
        $seen = [];
        foreach (array_keys($adj[$root]) as $next) {
            if (isset($seen[$next])) {
                continue;
            }
            $part = $this->component($without, $next);
            $seen += array_fill_keys($part, true);
            $runs[] = [$root, ...$part];
        }

        return $runs;
    }

    private function flow(string $color, array $adj, array $component, int $root): array
    {
        $inside = array_fill_keys($component, true);
        $local = [];
        foreach ($component as $id) {
            $local[$id] = array_intersect_key($adj[$id] ?? [], $inside);
        }
        $dist = $this->dijkstra($local, $root);
        $objects = [];
        $ends = [];
        foreach ($component as $id) {
            $n = $this->nodes[$id];
            if (in_array($n['kind'], ['zo', 'box'], true)) {
                $objects[] = ['node' => $id, 'distance_m' => round($dist[$id] ?? INF, 1)];
            } elseif ($id !== $root && count($local[$id]) === 1 && in_array($n['kind'], ['line', 'spur', 'izvod', 'slinga'], true)) {
                $ends[] = ['node' => $id, 'distance_m' => round($dist[$id] ?? INF, 1)];
            }
        }
        usort($objects, fn ($a, $b) => $a['distance_m'] <=> $b['distance_m']);
        usort($ends, fn ($a, $b) => $a['distance_m'] <=> $b['distance_m']);
        $length = 0.0;
        foreach ($local as $a => $row) {
            foreach ($row as $b => $w) {
                $length += $a < $b ? $w : 0;
            }
        }

        return [
            'color' => $color,
            'root' => $root,
            'length_m' => round($length, 1),
            'objects' => $objects,
            'ends' => $ends,
            'nodes' => $component,
        ];
    }

    /** ZOs grouped by ODF and secondary, each with the duct path from its ODF. */
    private function secondaries(): array
    {
        $odfs = [];
        foreach ($this->nodes as $n) {
            if ($n['kind'] === 'odf') {
                $odfs[$n['odf_no']] = $n['id'];
            }
        }
        $paths = [];
        foreach ($this->nodes as $n) {
            if ($n['kind'] === 'zo') {
                $odfId = $n['zo']['odf'] !== null ? ($odfs[$n['zo']['odf']] ?? null) : null;
                $paths[$n['id']] = $odfId !== null ? $this->tubePath($odfId, $n['id']) : null;
            }
        }

        $groups = [];
        foreach ($paths as $id => $path) {
            $zo = $this->nodes[$id]['zo'];
            $groups[($zo['odf'] ?? '?').'|'.($zo['secondary'] ?? '-')][] = [
                'node' => $id,
                'label' => $zo['label'],
                'reached' => $path !== null,
                'distance_m' => $path !== null ? round($path['length'], 1) : null,
                'via_zo' => $path !== null ? $this->upstreamClosures($id, $path['nodes']) : [],
            ];
        }
        ksort($groups, SORT_NATURAL);
        foreach ($groups as &$list) {
            usort($list, fn ($a, $b) => version_compare($a['label'], $b['label']));
        }

        return $groups;
    }

    /**
     * Closures of the same secondary that the cable passes before reaching
     * $zo: on the path itself, or standing beside a line point of the path.
     * Ordered from the ODF outwards.
     *
     * @return list<int>
     */
    private function upstreamClosures(int $zo, array $pathNodes): array
    {
        $position = array_flip($pathNodes);
        $own = $this->nodes[$zo]['zo'];
        $found = [];
        foreach ($this->nodes as $id => $n) {
            if ($id === $zo || $n['kind'] !== 'zo' || $n['zo']['odf'] !== $own['odf'] || $n['zo']['secondary'] !== $own['secondary']) {
                continue;
            }
            $at = $position[$id] ?? null;
            foreach ($this->linePointsOf($id) as $lineNode) {
                if (isset($position[$lineNode])) {
                    $at = min($at ?? PHP_INT_MAX, $position[$lineNode]);
                }
            }
            if ($at !== null && $at < count($pathNodes) - 1) {
                $found[$id] = $at;
            }
        }
        asort($found);

        return array_keys($found);
    }

    /** Line points an object hangs from (through its spur, if any). */
    private function linePointsOf(int $id): array
    {
        $result = [];
        $seen = [$id => true];
        $frontier = [$id];
        for ($depth = 0; $depth < 6 && $frontier !== []; $depth++) {
            $next = [];
            foreach ($frontier as $node) {
                foreach (array_keys($this->adj[$node] ?? []) as $other) {
                    if (isset($seen[$other])) {
                        continue;
                    }
                    $seen[$other] = true;
                    if ($this->nodes[$other]['kind'] === 'line') {
                        $result[] = $other;
                    } elseif (in_array($this->nodes[$other]['kind'], ['spur', 'slinga'], true)) {
                        $next[] = $other;
                    }
                }
            }
            $frontier = $next;
        }

        return $result;
    }

    /**
     * Shortest path from an ODF to a closure that a cable can really take:
     * it stays in one tube colour and changes tube only where fibres can be
     * spliced (ODF, ZO, or a tube end "izvod" where tubes are joined).
     *
     * @return array{length: float, nodes: list<int>}|null
     */
    private function tubePath(int $from, int $to): ?array
    {
        $switch = ['odf', 'zo', 'izvod'];
        $colorsAt = [];
        $starEdges = [];
        $moves = []; // node => list of [next, colour|*, weight]
        foreach ($this->edges as $e) {
            $ducts = $e['ducts'];
            if (is_array($ducts)) {
                unset($ducts['md']);
                if ($ducts === []) {
                    continue;
                }
                $colors = array_keys($ducts);
            } else {
                $colors = ['*'];
                $starEdges[] = $e;
            }
            $w = $this->dist($this->nodes[$e['a']], $this->nodes[$e['b']]);
            foreach ([[$e['a'], $e['b']], [$e['b'], $e['a']]] as [$u, $v]) {
                foreach ($colors as $c) {
                    $moves[$u][] = [$v, $c, $w];
                    if ($c !== '*') {
                        $colorsAt[$u][$c] = true;
                    }
                }
            }
        }

        // A closure joined by an "any tube" link offers the tubes of the line it stands on.
        foreach ($starEdges as $e) {
            $shared = ($colorsAt[$e['a']] ?? []) + ($colorsAt[$e['b']] ?? []);
            $colorsAt[$e['a']] = $shared;
            $colorsAt[$e['b']] = $shared;
        }

        $dist = [];
        $prev = [];
        $queue = new \SplPriorityQueue;
        foreach (array_keys($colorsAt[$from] ?? []) as $c) {
            $dist["$from|$c"] = 0.0;
            $queue->insert("$from|$c", 0.0);
        }
        foreach ($moves[$from] ?? [] as [$v, $c]) {
            if ($c === '*') { // ODF attached to its first trench point: start in every tube there
                foreach (array_keys($colorsAt[$v] ?? []) as $vc) {
                    $dist["$from|$vc"] = 0.0;
                    $queue->insert("$from|$vc", 0.0);
                }
            }
        }
        $goal = null;
        while (! $queue->isEmpty()) {
            $state = $queue->extract();
            [$u, $c] = explode('|', $state);
            $u = (int) $u;
            if ($u === $to) {
                $goal = $state;
                break;
            }
            $next = [];
            foreach ($moves[$u] ?? [] as [$v, $ec, $w]) {
                if ($ec === $c || $ec === '*') {
                    $next[] = [$v, $c, $w];
                }
            }
            if (in_array($this->nodes[$u]['kind'], $switch, true)) {
                foreach (array_keys($colorsAt[$u] ?? []) as $other) {
                    if ($other !== $c) {
                        $next[] = [$u, $other, 0.0];
                    }
                }
            }
            foreach ($next as [$v, $vc, $w]) {
                $key = "$v|$vc";
                $d = $dist[$state] + $w;
                if ($d < ($dist[$key] ?? INF)) {
                    $dist[$key] = $d;
                    $prev[$key] = $state;
                    $queue->insert($key, -$d);
                }
            }
        }
        if ($goal === null) {
            return null;
        }
        $nodes = [];
        for ($state = $goal; $state !== null; $state = $prev[$state] ?? null) {
            $node = (int) explode('|', $state)[0];
            if ($nodes === [] || $nodes[0] !== $node) {
                array_unshift($nodes, $node);
            }
        }

        return ['length' => $dist[$goal], 'nodes' => $nodes];
    }

    private function colorAdjacency(string $color): array
    {
        $adj = [];
        foreach ($this->edges as $e) {
            if ($e['ducts'] === self::ALL) {
                $touchesObject = in_array($this->nodes[$e['a']]['kind'], ['zo', 'box'], true)
                    || in_array($this->nodes[$e['b']]['kind'], ['zo', 'box'], true);
                $other = in_array($this->nodes[$e['a']]['kind'], ['zo', 'box', 'odf'], true) ? $e['b'] : $e['a'];
                if (($color === 'md' && $touchesObject) || ! isset($this->colorsOf($other)[$color])) {
                    continue;
                }
            } elseif (! isset($e['ducts'][$color])) {
                continue;
            }
            $w = $this->dist($this->nodes[$e['a']], $this->nodes[$e['b']]);
            $adj[$e['a']][$e['b']] = $w;
            $adj[$e['b']][$e['a']] = $w;
        }

        return $adj;
    }

    private function rootOf(array $component): int
    {
        $rank = function (int $id): array {
            $n = $this->nodes[$id];

            return match ($n['kind']) {
                'odf' => [0, $n['points'][0]],
                'zo' => [1, $n['points'][0]],
                default => [2, $n['points'][0]],
            };
        };
        usort($component, fn ($a, $b) => $rank($a) <=> $rank($b));

        return $component[0];
    }

    private function component(array $adj, int $start): array
    {
        $seen = [$start => true];
        $stack = [$start];
        while ($stack !== []) {
            $id = array_pop($stack);
            foreach (array_keys($adj[$id] ?? []) as $next) {
                if (! isset($seen[$next])) {
                    $seen[$next] = true;
                    $stack[] = $next;
                }
            }
        }

        return array_keys($seen);
    }

    /** @return array<int,float> */
    private function dijkstra(array $adj, int $root): array
    {
        $dist = [$root => 0.0];
        $queue = new \SplPriorityQueue;
        $queue->insert($root, 0.0);
        while (! $queue->isEmpty()) {
            $id = $queue->extract();
            foreach ($adj[$id] ?? [] as $next => $w) {
                $d = $dist[$id] + $w;
                if ($d < ($dist[$next] ?? INF)) {
                    $dist[$next] = $d;
                    $queue->insert($next, -$d);
                }
            }
        }

        return $dist;
    }

    // ------------------------------------------------------------------
    // Graph primitives
    // ------------------------------------------------------------------

    private function createNode(array $p, string $kind, array $extra = []): int
    {
        $id = $this->nextNode++;
        $this->nodes[$id] = array_merge([
            'id' => $id,
            'kind' => $kind,
            'x' => (float) $p['x'],
            'y' => (float) $p['y'],
            'points' => [$p['point_no']],
            'code' => $p['code'],
            'ducts' => $kind === 'odf' ? [] : ($p['parsed']['ducts'] ?? []),
            'odf_no' => null,
            'zo' => null,
        ], $extra);
        $this->adj[$id] = [];

        return $id;
    }

    /** @param array<string,int>|string $ducts */
    private function connect(int $a, int $b, array|string $ducts, string $kind, int $alkaten = 0): void
    {
        if ($a === $b) {
            return;
        }
        if (isset($this->adj[$a][$b])) {
            $index = $this->adj[$a][$b];
            if (is_array($ducts) && is_array($this->edges[$index]['ducts'])) {
                $this->edges[$index]['ducts'] = $this->mergeDucts($this->edges[$index]['ducts'], $ducts);
            }

            return;
        }
        $index = $this->nextEdge++;
        $this->edges[$index] = ['a' => $a, 'b' => $b, 'ducts' => $ducts, 'kind' => $kind, 'alkaten' => $alkaten];
        $this->adj[$a][$b] = $index;
        $this->adj[$b][$a] = $index;
    }

    private function removeEdge(int $index): void
    {
        $e = $this->edges[$index];
        unset($this->edges[$index], $this->adj[$e['a']][$e['b']], $this->adj[$e['b']][$e['a']]);
    }

    /** Trench neighbours only (spurs, objects and markers do not count). */
    private function lineDegree(int $id): int
    {
        return count(array_filter(
            array_keys($this->adj[$id] ?? []),
            fn (int $other) => $this->nodes[$other]['kind'] === 'line',
        ));
    }

    /** @return array<string,int> tubes on the node itself or any edge touching it */
    private function colorsOf(int $id): array
    {
        $colors = $this->nodes[$id]['ducts'];
        foreach ($this->adj[$id] ?? [] as $index) {
            if (is_array($this->edges[$index]['ducts'])) {
                $colors = $this->mergeDucts($colors, $this->edges[$index]['ducts']);
            }
        }

        return $colors;
    }

    private function nearest(array $p, callable $filter, float $maxM): ?int
    {
        $best = null;
        $bestD = $maxM;
        foreach ($this->nodes as $n) {
            if (! $filter($n)) {
                continue;
            }
            $d = $this->dist($n, $p);
            if ($d <= $bestD) {
                [$best, $bestD] = [$n['id'], $d];
            }
        }

        return $best;
    }

    private function dist(array $a, array $b): float
    {
        return hypot($a['x'] - $b['x'], $a['y'] - $b['y']);
    }

    private function overlap(array $a, array $b): int
    {
        return count(array_intersect_key($a, $b));
    }

    private function intersect(array $a, array $b): array
    {
        return array_intersect_key($b, $a);
    }

    private function mergeDucts(array $a, array $b): array
    {
        foreach ($b as $k => $v) {
            $a[$k] = max($a[$k] ?? 0, $v);
        }

        return $a;
    }

    private function warn(?int $pointNo, string $message): void
    {
        $this->warnings[] = ['point_no' => $pointNo, 'message' => $message];
    }
}
