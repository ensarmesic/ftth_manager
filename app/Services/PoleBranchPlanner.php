<?php

namespace App\Services;

use SplPriorityQueue;

/**
 * Connects poles into a tree of aerial branches ("krakovi"), independent of
 * survey order. Works on metric coordinates and has no database access.
 *
 * 1. Grow a tree from the root pole (Prim). A span costs its length, plus a
 *    penalty for turning away from the incoming direction, so the line follows
 *    a street instead of zig-zagging. Spans longer than $maxSpan are not used.
 * 2. A group of poles the tree cannot reach is hung on the nearest connected
 *    pole through one long span, which is reported so it can be checked.
 * 3. Split each tree into branches: a branch keeps going into the child with
 *    the most line behind it (preferring straight ahead); every other child
 *    starts a side branch (K1 -> K1.1, K1.2 ...).
 *
 * planFromLines() grows the trees from underground lines instead of one pole:
 * poles along a line are skipped (the cable is already underground there), and
 * each group of poles gets one riser, on the pole nearest the line. There a pole
 * also weighs how far it is from the line along the cable (Prim-Dijkstra
 * trade-off), so a street is fed from its near end, not from the far side.
 *
 * Survey numbers are a hint: crews number poles walking along a street, so a
 * span between consecutive numbers is cheaper and a branch prefers to follow them.
 *
 * Manual corrections win over everything: a "connect" span is always used (even
 * when longer than the max span) and a branch keeps going along it; a "cut" span
 * is never used.
 */
final class PoleBranchPlanner
{
    private const TURN_PENALTY = 0.6;

    private const CONTINUE_TURN_WEIGHT = 0.5;

    /** Poles this close to an underground line are not needed for the aerial network. */
    public const LINE_CORRIDOR_M = 12.0;

    /** Extra cost of a riser, so a street gets one riser instead of one per pole. */
    private const RISER_PENALTY_M = 30.0;

    /** Share of the distance already travelled from the line that a new span also pays. */
    private const PATH_WEIGHT = 0.3;

    /** Cost factor of a span between consecutive survey numbers. */
    private const SEQUENCE_DISCOUNT = 0.6;

    /** An ODF further than this from a line is not treated as lying on it. */
    private const ODF_ON_LINE_M = 30.0;

    /** @var array<int|string, array{0: float, 1: float}> */
    private array $points = [];

    /** @var array<int|string, int|string|null> */
    private array $parent = [];

    /** @var array<int|string, list<int|string>> */
    private array $children = [];

    /** @var array<int|string, float> */
    private array $subtreeLength = [];

    /** @var array<int|string, float> child id => length of an over-long span to it */
    private array $longSpans = [];

    /** @var list<int|string> */
    private array $roots = [];

    /** @var array<int|string, int> survey number per pole */
    private array $sequence = [];

    /** @var array<int|string, float> cable distance from the line (or root) to each connected pole */
    private array $reach = [];

    private float $pathWeight = 0.0;

    /** @var array<int|string, list<int|string>> forced spans per pole */
    private array $connect = [];

    /** @var array<string, true> forbidden spans, keyed "a|b" */
    private array $cut = [];

    /**
     * @param  list<array{0: int|string, 1: int|string}>  $connect  spans that must be used
     * @param  list<array{0: int|string, 1: int|string}>  $cut  spans that must not be used
     */
    public function withLinks(array $connect, array $cut): self
    {
        $this->connect = [];
        $this->cut = [];
        foreach ($connect as [$a, $b]) {
            $this->connect[$a][] = $b;
            $this->connect[$b][] = $a;
        }
        foreach ($cut as [$a, $b]) {
            $this->cut[$a.'|'.$b] = true;
            $this->cut[$b.'|'.$a] = true;
        }

        return $this;
    }

    /** @param  array<int|string, int>  $sequence  survey number per pole id (optional hint) */
    public function withSequence(array $sequence): self
    {
        $this->sequence = $sequence;

        return $this;
    }

    /**
     * Poles of $points connected into branches that start at underground $lines.
     *
     * @param  array<int|string, array{0: float, 1: float}>  $points  id => [x, y] in metres
     * @param  list<list<array{0: float, 1: float}>>  $lines  underground polylines in metres
     * @param  array<int|string, array{0: float, 1: float}>  $odfs  id => [x, y]; each branch is assigned to one
     * @return array{branches: list<array>, warnings: list<string>, skipped_ids: list<int|string>}
     */
    public function planFromLines(array $points, array $lines, array $odfs, float $maxSpan): array
    {
        $this->reset($points);
        $this->pathWeight = self::PATH_WEIGHT;
        $entries = array_map(fn (array $point) => $this->nearestOnLines($point, $lines), $points);
        $covered = array_filter($entries, fn (array $entry, $id) => $entry['distance'] <= self::LINE_CORRIDOR_M && ! isset($this->connect[$id]), ARRAY_FILTER_USE_BOTH);
        $open = array_diff_key($points, $covered);
        $grid = $this->grid($maxSpan, $open);
        $warnings = [];

        $remaining = $open;
        $queue = $this->queue();
        foreach (array_keys($open) as $id) {
            if ($entries[$id]['distance'] <= $maxSpan) {
                $queue->insert([null, $id, $entries[$id]['distance']], -($entries[$id]['distance'] + self::RISER_PENALTY_M));
            }
        }
        $this->drain($queue, $grid, $maxSpan, $remaining);
        while ($remaining !== []) {
            [$next, $attach, $gap] = $this->nearestRemaining($remaining);
            $nearLine = array_reduce(array_keys($remaining), fn ($best, $id) => $best === null || $entries[$id]['distance'] < $entries[$best]['distance'] ? $id : $best);
            $queue = $this->queue();
            if ($next === null || $entries[$nearLine]['distance'] <= $gap) {
                $warnings[] = sprintf('Grupa stubova je %.0f m od podzemne linije — provjeriti prelaz.', $entries[$nearLine]['distance']);
                $queue->insert([null, $nearLine, $entries[$nearLine]['distance']], 0);
            } else {
                $this->parent[$next] = $attach;
                $this->children[$attach][] = $next;
                $this->longSpans[$next] = $gap;
                $this->reach[$next] = ($this->reach[$attach] ?? 0.0) + $gap;
                $warnings[] = sprintf('Raspon od %.0f m je duži od dozvoljenih %.0f m — provjeriti da li nedostaje stub.', $gap, $maxSpan);
                unset($remaining[$next]);
                $this->pushNeighbours($next, $grid, $maxSpan, $remaining, $queue);
            }
            $this->drain($queue, $grid, $maxSpan, $remaining);
        }

        $this->moveRisersToLinePoles($covered, $entries, $maxSpan);
        $this->computeSubtreeLengths();

        // Branches are numbered per ODF, nearest to the ODF first.
        $odfEntries = array_map(fn (array $odf) => $this->nearestOnLines($odf, $lines), $odfs);
        $odfOrder = array_flip(array_keys($odfs));
        $ordered = [];
        foreach ($this->roots as $root) {
            [$odfId, $distance] = $this->odfFor($entries[$root], $odfs, $odfEntries);
            $ordered[] = ['root' => $root, 'odf_id' => $odfId, 'sort' => [$odfId === null ? PHP_INT_MAX : $odfOrder[$odfId], $distance]];
        }
        usort($ordered, fn (array $a, array $b) => $a['sort'] <=> $b['sort']);

        $branches = [];
        foreach ($ordered as $number => $item) {
            $first = count($branches);
            $this->walkBranch($item['root'], null, null, 'K'.($number + 1), null, $branches);
            for ($i = $first; $i < count($branches); $i++) {
                $branches[$i]['odf_id'] = $item['odf_id'];
            }
            $entry = $entries[$item['root']];
            $branches[$first]['entry'] = ['line' => $entry['line'], 'x' => $entry['x'], 'y' => $entry['y'], 'length_m' => round($entry['distance'], 1)];
        }
        $used = [];
        foreach ($branches as $branch) {
            foreach ($branch['pole_ids'] as $id) {
                $used[$id] = true;
            }
        }

        return [
            'branches' => $branches,
            'warnings' => $warnings,
            'skipped_ids' => array_values(array_filter(array_keys($covered), fn ($id) => ! isset($used[$id]))),
        ];
    }

    /**
     * A street usually starts at a pole standing next to the underground line:
     * the riser goes there, so the cable climbs where the line passes.
     */
    private function moveRisersToLinePoles(array $covered, array $entries, float $maxSpan): void
    {
        $roots = [];
        foreach ($this->roots as $root) {
            $best = null;
            $bestCost = INF;
            foreach (array_keys($covered) as $candidate) {
                if (isset($this->cut[$candidate.'|'.$root])) {
                    continue;
                }
                $span = $this->distance($candidate, $root);
                $cost = $span + $entries[$candidate]['distance'];
                if ($span <= $maxSpan && $cost < $bestCost) {
                    [$best, $bestCost] = [$candidate, $cost];
                }
            }
            if ($best === null) {
                $roots[] = $root;

                continue;
            }
            if (! array_key_exists($best, $this->parent)) {
                $this->parent[$best] = null;
                $roots[] = $best;
            }
            $this->parent[$root] = $best;
            $this->children[$best][] = $root;
        }
        $this->roots = $roots;
    }

    /** @return array{0: int|string|null, 1: float} the ODF feeding a riser and how far along the line it is */
    private function odfFor(array $entry, array $odfs, array $odfEntries): array
    {
        $best = [null, INF, true];
        foreach ($odfEntries as $id => $odfEntry) {
            $onLine = $odfEntry['line'] === $entry['line'] && $odfEntry['distance'] <= self::ODF_ON_LINE_M;
            $distance = $onLine
                ? abs($odfEntry['chainage'] - $entry['chainage'])
                : hypot($odfs[$id][0] - $entry['x'], $odfs[$id][1] - $entry['y']);
            // An ODF on the same line always wins over one that is only close by air.
            if ([! $onLine, $distance] < [! $best[2] || $best[0] === null, $best[1]]) {
                $best = [$id, $distance, $onLine];
            }
        }

        return [$best[0], $best[1]];
    }

    /** @return array{line: int, x: float, y: float, distance: float, chainage: float} */
    private function nearestOnLines(array $point, array $lines): array
    {
        $best = ['line' => -1, 'x' => $point[0], 'y' => $point[1], 'distance' => INF, 'chainage' => 0.0];
        foreach ($lines as $index => $line) {
            $chainage = 0.0;
            for ($i = 1; $i < count($line); $i++) {
                [$ax, $ay] = $line[$i - 1];
                [$bx, $by] = $line[$i];
                $dx = $bx - $ax;
                $dy = $by - $ay;
                $segment = hypot($dx, $dy);
                $t = $segment > 0 ? max(0.0, min(1.0, (($point[0] - $ax) * $dx + ($point[1] - $ay) * $dy) / ($segment * $segment))) : 0.0;
                $x = $ax + $t * $dx;
                $y = $ay + $t * $dy;
                $distance = hypot($point[0] - $x, $point[1] - $y);
                if ($distance < $best['distance']) {
                    $best = ['line' => $index, 'x' => $x, 'y' => $y, 'distance' => $distance, 'chainage' => $chainage + $t * $segment];
                }
                $chainage += $segment;
            }
        }

        return $best;
    }

    private function reset(array $points): void
    {
        $this->points = $points;
        $this->parent = [];
        $this->children = array_fill_keys(array_keys($points), []);
        $this->longSpans = [];
        $this->roots = [];
        $this->reach = [];
        $this->pathWeight = 0.0;
    }

    /**
     * @param  array<int|string, array{0: float, 1: float}>  $points  id => [x, y] in metres
     * @return array{branches: list<array>, warnings: list<string>}
     */
    public function plan(array $points, int|string|null $rootId, float $maxSpan): array
    {
        $this->reset($points);
        if ($points === []) {
            return ['branches' => [], 'warnings' => []];
        }
        $rootId = $rootId !== null && isset($points[$rootId]) ? $rootId : array_key_first($points);

        $grid = $this->grid($maxSpan, $points);
        $warnings = [];
        $remaining = $points;
        $this->parent[$rootId] = null;
        $next = $rootId;
        while ($next !== null) {
            $this->growTree($next, $grid, $maxSpan, $remaining);
            [$next, $attach, $gap] = $this->nearestRemaining($remaining);
            if ($next !== null) {
                $this->parent[$next] = $attach;
                $this->children[$attach][] = $next;
                $this->longSpans[$next] = $gap;
                $warnings[] = sprintf('Raspon od %.0f m je duži od dozvoljenih %.0f m — provjeriti da li nedostaje stub.', $gap, $maxSpan);
            }
        }

        $this->computeSubtreeLengths();
        $branches = [];
        // The first branch owns the root pole; every other root child starts its own top-level branch.
        $rootChildren = $this->sortedByWeight($this->children[$rootId]);
        $first = array_shift($rootChildren);
        $this->walkBranch($rootId, null, $first, 'K1', null, $branches);
        foreach ($rootChildren as $number => $child) {
            $this->walkBranch($child, $rootId, null, 'K'.($number + 2), null, $branches);
        }

        return ['branches' => $branches, 'warnings' => $warnings];
    }

    private function growTree(int|string $root, array $grid, float $maxSpan, array &$remaining): void
    {
        unset($remaining[$root]);
        $queue = $this->queue();
        $this->pushNeighbours($root, $grid, $maxSpan, $remaining, $queue);
        $this->drain($queue, $grid, $maxSpan, $remaining);
    }

    /** Prim's step. A queue item [null, $id] makes $id a tree root (a riser). */
    private function drain(SplPriorityQueue $queue, array $grid, float $maxSpan, array &$remaining): void
    {
        while (! $queue->isEmpty()) {
            $item = $queue->extract();
            [$from, $to] = $item;
            if (! isset($remaining[$to])) {
                continue;
            }
            unset($remaining[$to]);
            $this->parent[$to] = $from;
            $this->reach[$to] = $from === null ? (float) ($item[2] ?? 0.0) : ($this->reach[$from] ?? 0.0) + $this->distance($from, $to);
            if ($from === null) {
                $this->roots[] = $to;
            } else {
                $this->children[$from][] = $to;
            }
            $this->pushNeighbours($to, $grid, $maxSpan, $remaining, $queue);
        }
    }

    private function queue(): SplPriorityQueue
    {
        $queue = new SplPriorityQueue;
        $queue->setExtractFlags(SplPriorityQueue::EXTR_DATA);

        return $queue;
    }

    private function pushNeighbours(int|string $from, array $grid, float $maxSpan, array $remaining, SplPriorityQueue $queue): void
    {
        [$x, $y] = $this->points[$from];
        $cellX = (int) floor($x / $maxSpan);
        $cellY = (int) floor($y / $maxSpan);
        for ($gx = $cellX - 1; $gx <= $cellX + 1; $gx++) {
            for ($gy = $cellY - 1; $gy <= $cellY + 1; $gy++) {
                foreach ($grid[$gx][$gy] ?? [] as $to) {
                    if (! isset($remaining[$to]) || isset($this->cut[$from.'|'.$to])) {
                        continue;
                    }
                    $distance = $this->distance($from, $to);
                    if ($distance > $maxSpan) {
                        continue;
                    }
                    $cost = $distance * (1 + self::TURN_PENALTY * $this->turn($this->parent[$from] ?? null, $from, $to) / M_PI)
                        * ($this->consecutive($from, $to) ? self::SEQUENCE_DISCOUNT : 1.0)
                        + $this->pathWeight * ($this->reach[$from] ?? 0.0);
                    $queue->insert([$from, $to], -$cost);
                }
            }
        }
        // A forced span is taken as soon as one of its poles is connected.
        foreach ($this->connect[$from] ?? [] as $to) {
            if (isset($remaining[$to])) {
                $queue->insert([$from, $to], -($this->pathWeight * ($this->reach[$from] ?? 0.0)));
            }
        }
    }

    /** @return array{0: int|string|null, 1: int|string|null, 2: float|null} */
    private function nearestRemaining(array $remaining): array
    {
        $best = [null, null, null];
        $bestDistance = INF;
        foreach (array_keys($remaining) as $candidate) {
            foreach (array_keys($this->parent) as $connected) {
                if (isset($this->cut[$candidate.'|'.$connected])) {
                    continue;
                }
                $distance = $this->distance($candidate, $connected);
                if ($distance < $bestDistance) {
                    $bestDistance = $distance;
                    $best = [$candidate, $connected, $distance];
                }
            }
        }

        return $best;
    }

    private function computeSubtreeLengths(): void
    {
        $this->subtreeLength = [];
        foreach ($this->parent as $node => $parent) {
            if ($parent === null) {
                $this->subtreeLength($node);
            }
        }
    }

    private function subtreeLength(int|string $node): float
    {
        $length = 0.0;
        foreach ($this->children[$node] as $child) {
            $length += $this->subtreeLength($child) + $this->distance($node, $child);
        }

        return $this->subtreeLength[$node] = $length;
    }

    /**
     * Follows one branch from $start and recurses into its side branches.
     * $from is the pole on the parent branch it hangs from (null for a tree root).
     * $forcedNext is used only for a tree root, whose first child is chosen up front.
     */
    private function walkBranch(int|string $start, int|string|null $from, int|string|null $forcedNext, string $code, ?string $parentCode, array &$branches): void
    {
        $poles = [$start];
        $sides = [];
        $current = $start;
        $previous = $from;
        $candidates = $forcedNext === null ? $this->children[$start] : [$forcedNext];
        while ($candidates !== []) {
            $main = null;
            $mainScore = -INF;
            foreach ($candidates as $child) {
                $score = ($this->subtreeLength[$child] + $this->distance($current, $child))
                    * (1 - self::CONTINUE_TURN_WEIGHT * $this->turn($previous, $current, $child) / M_PI)
                    * ($this->consecutive($current, $child) ? 1 / self::SEQUENCE_DISCOUNT : 1.0)
                    * (in_array($child, $this->connect[$current] ?? [], true) ? 4.0 : 1.0);
                if ($score > $mainScore) {
                    [$main, $mainScore] = [$child, $score];
                }
            }
            foreach ($candidates as $child) {
                if ($child !== $main) {
                    $sides[] = [$current, $child];
                }
            }
            $poles[] = $main;
            [$previous, $current] = [$current, $main];
            $candidates = $this->children[$current];
        }

        $path = $from === null ? $poles : [$from, ...$poles];
        $length = 0.0;
        $longSpans = [];
        for ($i = 1; $i < count($path); $i++) {
            $length += $this->distance($path[$i - 1], $path[$i]);
            if (isset($this->longSpans[$path[$i]])) {
                $longSpans[] = ['from_id' => $path[$i - 1], 'to_id' => $path[$i], 'length_m' => round($this->longSpans[$path[$i]], 1)];
            }
        }
        $branches[] = [
            'code' => $code,
            'parent_code' => $parentCode,
            'depth' => substr_count($code, '.'),
            'from_id' => $from,
            'pole_ids' => $poles,
            'length_m' => round($length, 2),
            'long_spans' => $longSpans,
        ];

        // Side branches are numbered in the order they leave the branch, biggest first at a shared pole.
        $order = array_flip($poles);
        usort($sides, fn (array $a, array $b) => [$order[$a[0]], $this->weight($b[1])] <=> [$order[$b[0]], $this->weight($a[1])]);
        foreach ($sides as $number => [$attach, $child]) {
            $this->walkBranch($child, $attach, null, $code.'.'.($number + 1), $code, $branches);
        }
    }

    private function sortedByWeight(array $children): array
    {
        usort($children, fn ($a, $b) => $this->weight($b) <=> $this->weight($a));

        return $children;
    }

    private function weight(int|string $node): float
    {
        $parent = $this->parent[$node] ?? null;

        return $this->subtreeLength[$node] + ($parent === null ? 0.0 : $this->distance($parent, $node));
    }

    private function consecutive(int|string $a, int|string $b): bool
    {
        return isset($this->sequence[$a], $this->sequence[$b]) && abs($this->sequence[$a] - $this->sequence[$b]) === 1;
    }

    /** Angle in radians (0 = straight on, pi = turning back) between a->b and b->c. */
    private function turn(int|string|null $a, int|string $b, int|string $c): float
    {
        if ($a === null) {
            return 0.0;
        }
        [$ax, $ay] = $this->points[$a];
        [$bx, $by] = $this->points[$b];
        [$cx, $cy] = $this->points[$c];
        $in = atan2($by - $ay, $bx - $ax);
        $out = atan2($cy - $by, $cx - $bx);

        return abs(atan2(sin($out - $in), cos($out - $in)));
    }

    private function distance(int|string $a, int|string $b): float
    {
        return hypot($this->points[$a][0] - $this->points[$b][0], $this->points[$a][1] - $this->points[$b][1]);
    }

    private function grid(float $size, array $points): array
    {
        $grid = [];
        foreach ($points as $id => [$x, $y]) {
            $grid[(int) floor($x / $size)][(int) floor($y / $size)][] = $id;
        }

        return $grid;
    }
}
