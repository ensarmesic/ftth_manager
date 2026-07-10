<?php

namespace App\Services;

use App\Models\Cabinet;
use App\Models\House;
use App\Models\NetworkRoute;
use App\Models\Odf;
use App\Models\Project;
use App\Models\ProjectAppendixItem;
use App\Models\SurveyPoint;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Imports geodetic survey points from the surveyor's TXT export
 * (`broj  X  Y  Z  opis`, Gauss-Krüger) and reconstructs the network from
 * them as a GRAPH, not as a sequence:
 *
 *  1. Every trench point is a graph node; points that physically coincide
 *     (≤ NODE_MERGE_M) are merged — the surveyor returning to a fork, or
 *     logging a section in reverse, lands on the same node.
 *  2. Consecutive trench points in the walk (gap ≤ TRENCH_GAP_M) become
 *     edges. Each edge carries the DUCT identities read from the field
 *     description: "14/10 mc Zelena + Plava" = green and blue ducts,
 *     "10/8 mc x 2 - ZO 3" = two 10/8s belonging to cabinet ZO 3.
 *  3. Physical trenches = chains of the full graph cut at junctions.
 *     Each duct = chains of its identity's subgraph (additionally cut where
 *     the duct count changes), so branches keep their real ENDS at houses
 *     and backbones stay continuous through forks regardless of survey
 *     order, direction or description typos.
 */
class SurveyPointImportService
{
    /** Points in a trench are logged every 2-10 m; a bigger jump within the
     *  walk means the surveyor moved to another branch. */
    private const TRENCH_GAP_M = 20.0;

    /** Two survey points closer than this are the same physical spot. */
    private const NODE_MERGE_M = 1.5;

    private const ODF_MERGE_M = 15.0;

    private const EXISTING_ELEMENT_TOLERANCE_M = 5.0;

    private const DUCT_ENDPOINT_BIND_M = 30.0;

    private const COLOR_WORDS = [
        'zelen' => 'Zelena', 'crven' => 'Crvena', 'plav' => 'Plava', 'zut' => 'Zuta',
        'bjel' => 'Bjela', 'bijel' => 'Bjela', 'narandz' => 'Narandzasta', 'ljubicast' => 'Ljubicasta', 'siv' => 'Siva',
    ];

    private const COLOR_ABBREVIATIONS = [
        'ze' => 'Zelena', 'cr' => 'Crvena', 'pl' => 'Plava', 'zu' => 'Zuta', 'bj' => 'Bjela',
    ];

    public function __construct(
        private readonly GeoTransformService $transform,
        private readonly GeometryService $geometry,
        private readonly BranchSyncService $branchSync,
    ) {}

    // -------------------------------------------------------------------------
    // Parsing & classification
    // -------------------------------------------------------------------------

    /**
     * Parse the raw TXT content into classified points (no DB writes).
     * Handles "glued" lines where the instrument merged two records into one.
     */
    public function parse(string $contents): array
    {
        $pattern = '/(\d{1,5})\s+([4-7]\d{6}(?:\.\d{1,3})?)\s+([3-5]\d{6}(?:\.\d{1,3})?)\s+(-?\d{1,4}(?:\.\d{1,3})?)[ \t]*/';
        if (! preg_match_all($pattern, $contents, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            return [];
        }

        $points = [];
        foreach ($matches as $index => $match) {
            $start = $match[0][1] + strlen($match[0][0]);
            $end = isset($matches[$index + 1]) ? $matches[$index + 1][0][1] : strlen($contents);
            $code = trim(str_replace(['"', "\r"], '', substr($contents, $start, $end - $start)));
            $code = trim(preg_replace('/\s+/', ' ', $code) ?? '');

            $x = (float) $match[2][0];
            $y = (float) $match[3][0];
            $zone = $this->transform->detectZone($x);
            [$lat, $lng] = $this->transform->gaussKrugerToWgs84($x, $y, $zone);

            $points[] = [
                'point_no' => (int) $match[1][0],
                'x' => $x,
                'y' => $y,
                'z' => (float) $match[4][0],
                'code' => $code,
                'lat' => $lat,
                'lng' => $lng,
            ] + $this->classify($code);
        }

        return $points;
    }

    /**
     * Classify a free-hand surveyor description.
     *
     * @return array{kind:string,microduct_type:?string,microduct_count:int,colors:array,zo_tag:?string,has_sling:bool}
     */
    public function classify(string $code): array
    {
        $n = mb_strtolower(trim($code));
        $n = strtr($n, ['š' => 's', 'ž' => 'z', 'č' => 'c', 'ć' => 'c', 'đ' => 'dj']);

        $microductType = null;
        if (preg_match('/14\s*\/?\s*(10|12)|(?<!\d)14\s*mc/', $n)) {
            $microductType = '14/10';
        } elseif (preg_match('/10\s*\/\s*[78]|10\/\/8|\dx10|mc\s*10\b|mc\.\s*10/', $n)) {
            $microductType = '10/8';
        }

        $microductCount = 1;
        if (preg_match('/(?:^|[^\d])x\s*(\d{1,2})(?:\.\d)?\b/', $n, $m)) {
            $microductCount = max(1, (int) $m[1]);
        } elseif (preg_match('/(?:^|[^\d])(\d{1,2})\s*x\s*1[04]/', $n, $m)) {
            $microductCount = max(1, (int) $m[1]);
        }

        // Duct colours: full words ("Zelena i Plava") and abbreviations ("Ze+Pl+Cr").
        $colors = [];
        foreach (self::COLOR_WORDS as $stem => $color) {
            if (preg_match('/'.$stem.'[a-z]*/', $n)) {
                $colors[$color] = $color;
            }
        }
        if (preg_match_all('/[+\-]\s*(ze|cr|pl|zu|bj)\b/', $n, $abbr)) {
            foreach ($abbr[1] as $a) {
                $color = self::COLOR_ABBREVIATIONS[$a];
                $colors[$color] = $color;
            }
        }
        $colors = array_values($colors);

        // Which cabinet the duct belongs to: "- ZO 3", "_ZO_1.1", "-Z0-02", "Z 7.00"...
        $zoTag = null;
        if (preg_match_all('/z\s*[o0][\s\-_.]*([0-9]+(?:[.\-][0-9]+)*)/', $n, $m) && count($m[1]) > 0) {
            $zoTag = $this->normalizeZoTag(end($m[1]));
        } elseif (preg_match('/[\s\-_]z\s+([0-9]+(?:\.[0-9]+)*)/', $n, $m)) {
            $zoTag = $this->normalizeZoTag($m[1]);
        }

        $isHousePoint = $this->isHousePoint($n);

        $kind = match (true) {
            $n === '' => 'other',
            $isHousePoint => 'sling',
            (bool) preg_match('/\brov\b|\brob\b|rov\+|^mikrodukt/', $n) => 'trench',
            (bool) preg_match('/spojnic/', $n) => 'splice',
            (bool) preg_match('/sling|slinga|izvod|sluga\b/', $n) => 'sling',
            (bool) preg_match('/\bsaht\b/', $n) => 'manhole',
            (bool) preg_match('/busenje/', $n) => 'boring',
            (bool) preg_match('/\bstub\b/', $n) => 'pole',
            (bool) preg_match('/odf/', $n) => 'odf',
            (bool) preg_match('/zeleni\s*ormar|^z\s*[o0](?![a-z])/', $n) => 'cabinet',
            default => 'other',
        };

        return [
            'kind' => $kind,
            'microduct_type' => $microductType,
            'microduct_count' => $microductCount,
            'colors' => $colors,
            'zo_tag' => $zoTag,
            'duct_identities' => $this->parseMultipleDuctIdentities($n),
            // "Rov +Šlinga ..." — a trench point that ALSO marks a prepared
            // house connection at that spot.
            'has_sling' => $kind === 'trench' && (bool) preg_match('/sling|linga/', $n),
        ];
    }

    /**
     * A semicolon or pipe separates physical ducts recorded at the same point:
     * "Rov; 14/10 Zelena; 14/10 Plava; 10/8 X1 ZO 3".
     */
    private function parseMultipleDuctIdentities(string $description): array
    {
        $parts = preg_split('/\s*[;|]\s*/', $description) ?: [];
        if (count($parts) < 2) {
            return [];
        }

        $identities = [];
        foreach ($parts as $part) {
            $type = match (true) {
                (bool) preg_match('/14\s*\/?\s*(10|12)|(?<!\d)14\s*mc/', $part) => '14/10',
                (bool) preg_match('/10\s*\/\s*[78]|10\/\/8|\dx10|mc\s*10\b|mc\.\s*10/', $part) => '10/8',
                default => null,
            };
            if ($type === null) {
                continue;
            }

            $count = 1;
            if (preg_match('/(?:^|[^\d])x\s*(\d{1,2})(?:\.\d)?\b/', $part, $m)
                || preg_match('/(?:^|[^\d])(\d{1,2})\s*x\s*1[04]/', $part, $m)) {
                $count = max(1, (int) $m[1]);
            }

            $colors = [];
            foreach (self::COLOR_WORDS as $stem => $color) {
                if (preg_match('/'.$stem.'[a-z]*/', $part)) {
                    $colors[$color] = $color;
                }
            }
            if (preg_match_all('/[+\-]\s*(ze|cr|pl|zu|bj)\b/', $part, $matches)) {
                foreach ($matches[1] as $abbreviation) {
                    $colors[self::COLOR_ABBREVIATIONS[$abbreviation]] = self::COLOR_ABBREVIATIONS[$abbreviation];
                }
            }

            $tag = null;
            if (preg_match_all('/z\s*[o0][\s\-_.]*([0-9]+(?:[.\-][0-9]+)*)/', $part, $matches) && count($matches[1]) > 0) {
                $tag = $this->normalizeZoTag(end($matches[1]));
            }

            $identities[] = [
                'type' => $type,
                'count' => $count,
                'colors' => array_values($colors),
                'tag' => $tag,
            ];
        }

        return count($identities) > 1 ? $identities : [];
    }

    // -------------------------------------------------------------------------
    // Graph reconstruction
    // -------------------------------------------------------------------------

    /**
     * Build the trench/duct network from classified points.
     *
     * @return array{trenches: array, ducts: array}
     */
    public function buildNetwork(array $points): array
    {
        $trenchPoints = array_values(array_filter($points, fn ($p) => $p['kind'] === 'trench'));
        $ductPoints = array_values(array_filter($points, fn ($p) => $p['kind'] === 'trench' || $p['kind'] === 'sling'));

        [$trenchNodes, $trenchEdges] = $this->buildGraph($trenchPoints);
        $trenches = [];
        if (count($trenchEdges) > 0) {
            $trenchPaths = array_map(
                fn (array $chain) => array_map(fn (int $node) => $trenchNodes[$node], $chain['nodes']),
                $this->walkChains($trenchEdges, $trenchNodes, null)
            );
            $trenchPaths = $this->mergeCollinearChains($trenchPaths);

            foreach ($trenchPaths as $path) {
                $trenches[] = [
                    'path' => $path,
                    'points' => count($path),
                    'length_m' => $this->geometry->polylineLength($path),
                    'code' => 'rov',
                ];
            }
        }

        [$ductNodes, $ductEdges] = $this->buildGraph($ductPoints);

        // --- ducts: per-identity subgraph, additionally cut where count changes ---
        $identAttrs = [];
        $identEdges = [];
        foreach ($ductEdges as $edgeIndex => $edge) {
            foreach ($edge['idents'] as $ik => $attrs) {
                $identAttrs[$ik] = $attrs;
                $identEdges[$ik][] = $edgeIndex;
            }
        }

        $ducts = [];
        foreach ($identEdges as $ik => $edgeIndexes) {
            $sub = array_map(fn (int $ei) => $ductEdges[$ei] + ['group' => $ductEdges[$ei]['idents'][$ik]['count']], $edgeIndexes);
            $byCount = [];
            foreach ($this->walkChains($sub, $ductNodes, fn (array $e) => $e['group']) as $chain) {
                if (count($chain['nodes']) < 2) {
                    continue;
                }
                $chainCount = $sub[$chain['edges'][0]]['group'];
                $byCount[$chainCount][] = array_map(fn (int $node) => $ductNodes[$node], $chain['nodes']);
            }

            $attrs = $identAttrs[$ik];
            foreach ($byCount as $chainCount => $paths) {
                // A colour identifies ONE physical duct network-wide, so its
                // chain ends weld across the small unsurveyed skips at taps.
                if ($attrs['color'] !== null) {
                    $paths = $this->weldChainEnds($paths, 10.0);
                }
                foreach ($paths as $path) {
                    $ducts[] = [
                        'key' => $ik,
                        'label' => $this->ductLabel($attrs, (int) $chainCount),
                        'microduct_type' => $attrs['type'],
                        'microduct_count' => (int) $chainCount,
                        'color' => $attrs['color'],
                        'zo_tag' => $attrs['tag'],
                        'path' => $path,
                        'length_m' => $this->geometry->polylineLength($path),
                    ];
                }
            }
        }

        return ['trenches' => $trenches, 'ducts' => $this->inferImplicitCabinetTags($ducts)];
    }

    /**
     * Build a graph from ordered survey points by merging nearby nodes and
     * creating edges between consecutive points in the original walk.
     *
     * @return array{0: array<int,array<float,float>>,1: array}
     */
    private function buildGraph(array $points): array
    {
        $count = count($points);
        if ($count < 2) {
            return [[], []];
        }

        $parent = range(0, $count - 1);
        $find = function (int $i) use (&$parent, &$find): int {
            return $parent[$i] === $i ? $i : ($parent[$i] = $find($parent[$i]));
        };

        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                // cheap pre-filter before the exact distance
                if (abs($points[$i]['lat'] - $points[$j]['lat']) > 0.00003) {
                    continue;
                }
                if (abs($points[$i]['lng'] - $points[$j]['lng']) > 0.00005) {
                    continue;
                }
                if ($this->geometry->distanceMeters(
                    $points[$i]['lat'], $points[$i]['lng'],
                    $points[$j]['lat'], $points[$j]['lng']
                ) <= self::NODE_MERGE_M) {
                    $parent[$find($j)] = $find($i);
                }
            }
        }

        $nodeOf = [];
        $nodes = [];
        for ($i = 0; $i < $count; $i++) {
            $root = $find($i);
            if (! isset($nodes[$root])) {
                $nodes[$root] = [$points[$root]['lat'], $points[$root]['lng']];
            }
            $nodeOf[$i] = $root;
        }

        $edges = [];
        for ($i = 1; $i < $count; $i++) {
            $a = $nodeOf[$i - 1];
            $b = $nodeOf[$i];
            if ($a === $b) {
                continue;
            }

            $gap = $this->geometry->distanceMeters(
                $points[$i - 1]['lat'], $points[$i - 1]['lng'],
                $points[$i]['lat'], $points[$i]['lng']
            );
            if ($gap > self::TRENCH_GAP_M) {
                continue;
            }

            $fromIdents = $this->pointDuctIdentities($points[$i - 1]);
            $toIdents = $this->pointDuctIdentities($points[$i]);
            $shared = array_intersect_key($fromIdents, $toIdents);
            if (count($shared) > 0) {
                $idents = $shared;
            } elseif (count($fromIdents) === 0) {
                $idents = $toIdents;
            } elseif (count($toIdents) === 0) {
                $idents = $fromIdents;
            } else {
                $idents = [];
            }

            $key = min($a, $b).'|'.max($a, $b);
            if (! isset($edges[$key])) {
                $edges[$key] = ['a' => $a, 'b' => $b, 'idents' => $idents];
            } else {
                foreach ($idents as $ik => $attrs) {
                    $existing = $edges[$key]['idents'][$ik] ?? null;
                    $edges[$key]['idents'][$ik] = $existing
                        ? ['count' => max($existing['count'], $attrs['count'])] + $existing
                        : $attrs;
                }
            }
        }

        return [$nodes, array_values($edges)];
    }

    /**
     * Duct identities present at a single survey point.
     *
     * @return array<string, array{count:int,type:string,color:?string,tag:?string}>
     */
    private function pointDuctIdentities(array $point): array
    {
        if (! empty($point['duct_identities'])) {
            $idents = [];
            foreach ($point['duct_identities'] as $identity) {
                $colors = $identity['type'] === '14/10' ? $identity['colors'] : [];
                if (count($colors) > 0) {
                    foreach ($colors as $color) {
                        $idents[$identity['type'].'|'.$color] = [
                            'count' => $identity['count'],
                            'type' => $identity['type'],
                            'color' => $color,
                            'tag' => $identity['tag'],
                        ];
                    }

                    continue;
                }

                $tag = $identity['tag'];
                $key = $identity['type'].'|'.($tag !== null ? 'zo:'.$tag : 'anon');
                $idents[$key] = [
                    'count' => $identity['count'],
                    'type' => $identity['type'],
                    'color' => null,
                    'tag' => $tag,
                ];
            }

            return $idents;
        }

        if (! $point['microduct_type']) {
            return []; // "Rov", "Rov +MD" — trench without duct info
        }

        $idents = [];
        if ($point['microduct_type'] === '14/10' && count($point['colors'] ?? []) > 0) {
            foreach ($point['colors'] as $color) {
                $idents['14/10|'.$color] = [
                    'count' => $point['microduct_count'],
                    'type' => '14/10',
                    'color' => $color,
                    'tag' => $point['zo_tag'],
                ];
            }
        } else {
            $tag = $point['zo_tag'];
            $key = $point['microduct_type'].'|'.($tag !== null ? 'zo:'.$tag : 'anon');
            $idents[$key] = [
                'count' => $point['microduct_count'],
                'type' => $point['microduct_type'],
                'color' => $point['colors'][0] ?? null,
                'tag' => $tag,
            ];
        }

        return $idents;
    }

    private function inferImplicitCabinetTags(array $ducts): array
    {
        foreach ($ducts as $index => $duct) {
            if ($duct['zo_tag'] !== null || count($duct['path']) < 2) {
                continue;
            }

            $tags = [];
            foreach ($ducts as $candidateIndex => $candidate) {
                if ($candidateIndex === $index || $candidate['zo_tag'] === null) {
                    continue;
                }
                foreach ($this->pathEndpoints($duct['path']) as $endpoint) {
                    if ($this->geometry->distanceToRoute($endpoint[0], $endpoint[1], $candidate['path']) <= self::NODE_MERGE_M) {
                        $tags[$candidate['zo_tag']] = true;
                    }
                }
            }

            if (count($tags) !== 1) {
                continue;
            }

            $tag = (string) array_key_first($tags);
            $ducts[$index]['zo_tag'] = $tag;
            $ducts[$index]['key'] = $duct['microduct_type'].'|zo:'.$tag;
            $ducts[$index]['label'] = $this->ductLabel([
                'type' => $duct['microduct_type'],
                'color' => $duct['color'],
                'tag' => $tag,
            ], $duct['microduct_count']);
        }

        return $ducts;
    }

    private function ductLabel(array $attrs, int $count): string
    {
        $countLabel = $count > 1 ? $count.'x' : '';
        $suffix = $attrs['color'] ?? ($attrs['tag'] !== null ? 'ZO '.$attrs['tag'] : '');

        return trim('MC '.$countLabel.$attrs['type'].' '.$suffix);
    }

    private function isHousePoint(string $n): bool
    {
        return (bool) preg_match('/\b(?:kuc[aeiou]*|kuci|kucu|kuce|za kuc[aeiou]*|do kuc[aeiou]*|na kuci|kuci)\b/u', $n);
    }

    /**
     * Re-join trench chains that meet end-to-end and continue in nearly the
     * same direction — a backbone crossing a branch tap reads as ONE dig, not
     * a dozen stubs. Branch chains (sharp angles) stay separate.
     */
    private function mergeCollinearChains(array $paths, float $touchM = 1.0, float $minCos = 0.5): array
    {
        $merged = true;
        while ($merged) {
            $merged = false;
            $n = count($paths);
            for ($i = 0; $i < $n && ! $merged; $i++) {
                for ($j = $i + 1; $j < $n && ! $merged; $j++) {
                    foreach ([[true, false], [true, true], [false, false], [false, true]] as [$iAtEnd, $jAtEnd]) {
                        $a = $paths[$i];
                        $b = $paths[$j];
                        $pa = $iAtEnd ? end($a) : $a[0];
                        $pb = $jAtEnd ? end($b) : $b[0];
                        if ($this->geometry->distanceBetweenPoints($pa, $pb) > $touchM) {
                            continue;
                        }
                        // directions leaving the shared node must be opposite
                        // (the dig goes straight through the junction)
                        $da = $this->chainDirection($a, $iAtEnd);
                        $db = $this->chainDirection($b, $jAtEnd);
                        $dot = $da[0] * $db[0] + $da[1] * $db[1];
                        if ($dot > -$minCos) {
                            continue;
                        }

                        $first = $iAtEnd ? $a : array_reverse($a);
                        $second = $jAtEnd ? array_reverse($b) : $b;
                        array_shift($second); // shared node
                        $paths[$i] = array_merge($first, $second);
                        array_splice($paths, $j, 1);
                        $merged = true;
                        break;
                    }
                }
            }
        }

        return array_values($paths);
    }

    /**
     * Unit direction of the chain at one of its endpoints, pointing AWAY from
     * the chain (outward), in local metric coordinates.
     */
    private function chainDirection(array $path, bool $atEnd): array
    {
        $tip = $atEnd ? end($path) : $path[0];
        $inner = $atEnd ? $path[count($path) - 2] : $path[1];
        $dx = ($tip[1] - $inner[1]) * cos(deg2rad($tip[0]));
        $dy = $tip[0] - $inner[0];
        $len = sqrt($dx * $dx + $dy * $dy) ?: 1e-12;

        return [$dx / $len, $dy / $len];
    }

    /**
     * Weld chains of the SAME physical duct whose endpoints nearly touch —
     * covers the few unsurveyed metres at a tap where the walk skipped.
     */
    private function weldChainEnds(array $paths, float $toleranceM): array
    {
        $merged = true;
        while ($merged) {
            $merged = false;
            $n = count($paths);
            for ($i = 0; $i < $n && ! $merged; $i++) {
                for ($j = $i + 1; $j < $n && ! $merged; $j++) {
                    foreach ([[true, false], [true, true], [false, false], [false, true]] as [$iAtEnd, $jAtEnd]) {
                        $a = $paths[$i];
                        $b = $paths[$j];
                        $pa = $iAtEnd ? end($a) : $a[0];
                        $pb = $jAtEnd ? end($b) : $b[0];
                        if ($this->geometry->distanceBetweenPoints($pa, $pb) > $toleranceM) {
                            continue;
                        }

                        $first = $iAtEnd ? $a : array_reverse($a);
                        $second = $jAtEnd ? array_reverse($b) : $b;
                        if ($this->geometry->distanceBetweenPoints(end($first), $second[0]) < 0.5) {
                            array_shift($second);
                        }
                        $paths[$i] = array_merge($first, $second);
                        array_splice($paths, $j, 1);
                        $merged = true;
                        break;
                    }
                }
            }
        }

        return array_values($paths);
    }

    /**
     * Split an edge set into chains. Chains are cut at junction nodes
     * (degree ≠ 2), and — when $groupOf is given — wherever two adjacent
     * edges belong to different groups (e.g. the duct count changes).
     *
     * @param  array  $edgeList  each ['a' => nodeId, 'b' => nodeId, ...]
     * @param  callable|null  $groupOf  fn(edge): scalar
     * @return array<int, array{nodes: int[], edges: int[]}>
     */
    private function walkChains(array $edgeList, array $nodes, ?callable $groupOf): array
    {
        $adjacency = [];
        foreach ($edgeList as $index => $edge) {
            $adjacency[$edge['a']][] = $index;
            $adjacency[$edge['b']][] = $index;
        }

        $isCut = function (int $node) use ($adjacency, $edgeList, $groupOf): bool {
            $incident = $adjacency[$node] ?? [];
            if (count($incident) !== 2) {
                return true;
            }

            return $groupOf !== null
                && $groupOf($edgeList[$incident[0]]) !== $groupOf($edgeList[$incident[1]]);
        };

        $visited = [];
        $chains = [];

        $walk = function (int $startNode, int $firstEdge) use (&$visited, $adjacency, $edgeList, $isCut, &$chains): void {
            $chainNodes = [$startNode];
            $chainEdges = [];
            $current = $startNode;
            $edge = $firstEdge;

            while (true) {
                $visited[$edge] = true;
                $chainEdges[] = $edge;
                $current = $edgeList[$edge]['a'] === $current ? $edgeList[$edge]['b'] : $edgeList[$edge]['a'];
                $chainNodes[] = $current;

                if ($isCut($current)) {
                    break;
                }
                $next = null;
                foreach ($adjacency[$current] as $candidate) {
                    if (empty($visited[$candidate])) {
                        $next = $candidate;
                        break;
                    }
                }
                if ($next === null) {
                    break;
                }
                $edge = $next;
            }

            $chains[] = ['nodes' => $chainNodes, 'edges' => $chainEdges];
        };

        foreach (array_keys($adjacency) as $node) {
            if (! $isCut($node)) {
                continue;
            }
            foreach ($adjacency[$node] as $edgeIndex) {
                if (empty($visited[$edgeIndex])) {
                    $walk($node, $edgeIndex);
                }
            }
        }
        // leftovers are pure loops — start anywhere
        foreach ($edgeList as $edgeIndex => $edge) {
            if (empty($visited[$edgeIndex])) {
                $walk($edge['a'], $edgeIndex);
            }
        }

        return $chains;
    }

    // -------------------------------------------------------------------------
    // Preview & confirm
    // -------------------------------------------------------------------------

    public function preview(Project $project, string $contents, string $filename = ''): array
    {
        $points = $this->parse($contents);
        if (count($points) < 1) {
            throw new InvalidArgumentException('Fajl ne sadrzi nijednu prepoznatljivu tacku (broj X Y Z opis).');
        }

        $batch = sha1($contents);
        $alreadyImported = SurveyPoint::where('project_id', $project->id)->where('import_batch', $batch)->exists();

        $network = $this->buildNetwork($points);

        return [
            'batch' => $batch,
            'filename' => $filename,
            'already_imported' => $alreadyImported,
            'total_points' => count($points),
            'by_kind' => collect($points)->groupBy('kind')->map->count()->all(),
            'trench_runs' => collect($network['trenches'])->map(fn (array $chain) => [
                'code' => $chain['code'],
                'points' => $chain['points'],
                'length_m' => $chain['length_m'],
                'microduct_type' => null,
                'microduct_count' => 0,
            ])->values()->all(),
            'trench_total_m' => array_sum(array_column($network['trenches'], 'length_m')),
            'ducts' => collect($network['ducts'])->map(fn (array $duct) => [
                'label' => $duct['label'],
                'length_m' => $duct['length_m'],
                'color' => $duct['color'],
                'zo_tag' => $duct['zo_tag'],
            ])->values()->all(),
            'cabinets' => collect($points)->where('kind', 'cabinet')->map(fn ($p) => ['code' => $p['code'], 'lat' => $p['lat'], 'lng' => $p['lng']])->values()->all(),
            'odfs' => $this->mergeOdfPoints($points),
            'manholes' => collect($points)->where('kind', 'manhole')->count(),
            'houses' => collect($points)->filter(fn ($p) => $p['kind'] === 'sling' || ! empty($p['has_sling']))->count(),
            'unrecognized_codes' => collect($points)->where('kind', 'other')->pluck('code')->filter()->unique()->values()->all(),
            'bounds' => [
                'lat' => [collect($points)->min('lat'), collect($points)->max('lat')],
                'lng' => [collect($points)->min('lng'), collect($points)->max('lng')],
            ],
        ];
    }

    public function confirm(Project $project, string $contents, string $filename = ''): array
    {
        $points = $this->parse($contents);
        if (count($points) < 1) {
            throw new InvalidArgumentException('Fajl ne sadrzi nijednu prepoznatljivu tacku.');
        }

        $batch = sha1($contents);
        if (SurveyPoint::where('project_id', $project->id)->where('import_batch', $batch)->exists()) {
            throw new InvalidArgumentException('Ovaj fajl je vec uvezen u ovaj projekat.');
        }

        $created = ['points' => 0, 'trenches' => 0, 'ducts' => 0, 'cabinets' => 0, 'odfs' => 0, 'manholes' => 0, 'houses' => 0];

        DB::transaction(function () use ($project, $points, $batch, $filename, &$created): void {
            foreach ($points as $point) {
                SurveyPoint::create([
                    'project_id' => $project->id,
                    'import_batch' => $batch,
                    'source_file' => $filename ?: null,
                    'point_no' => $point['point_no'],
                    'x' => $point['x'],
                    'y' => $point['y'],
                    'z' => $point['z'],
                    'latitude' => $point['lat'],
                    'longitude' => $point['lng'],
                    'code' => $point['code'],
                    'kind' => $point['kind'],
                ]);
                $created['points']++;
            }

            // 1. Cabinets and ODFs first, so ducts can bind to them.
            foreach (collect($points)->where('kind', 'cabinet') as $point) {
                if ($this->existsNearby(Cabinet::class, $project->id, $point['lat'], $point['lng'])) {
                    continue;
                }
                Cabinet::create([
                    'project_id' => $project->id,
                    'name' => $this->uniqueName(Cabinet::class, $project->id, $this->cabinetLabel($point['code'])),
                    'address' => 'Geodetski snimak',
                    'splitter_count' => 3,
                    'ports_per_splitter' => 4,
                    'latitude' => $point['lat'],
                    'longitude' => $point['lng'],
                ]);
                $created['cabinets']++;
            }
            $allCabinets = Cabinet::where('project_id', $project->id)->whereNotNull('latitude')->get();

            foreach ($this->mergeOdfPoints($points) as $odfPoint) {
                if ($this->existsNearby(Odf::class, $project->id, $odfPoint['lat'], $odfPoint['lng'])) {
                    continue;
                }
                Odf::create([
                    'project_id' => $project->id,
                    'name' => $this->uniqueName(Odf::class, $project->id, 'ODF'),
                    'address' => 'Geodetski snimak',
                    'fiber_capacity' => 144,
                    'port_count' => 48,
                    'latitude' => $odfPoint['lat'],
                    'longitude' => $odfPoint['lng'],
                ]);
                $created['odfs']++;
            }
            $allOdfs = Odf::where('project_id', $project->id)->whereNotNull('latitude')->get();

            $network = $this->buildNetwork($points);

            // 2. Physical trenches.
            foreach ($network['trenches'] as $index => $chain) {
                $existing = $this->findExistingRouteGeometry($project->id, 'trench', $chain['path']);
                if ($existing) {
                    $mergedPath = $this->mergeTouchingPaths($existing->path ?? [], $chain['path']);
                    $existing->update([
                        'path' => $mergedPath,
                        'duct_length_m' => $this->geometry->polylineLength($mergedPath),
                        'note' => $this->appendImportNote($existing->note, 'Geodetski snimak: '.$chain['code']),
                    ]);

                    continue;
                }

                NetworkRoute::create([
                    'project_id' => $project->id,
                    'name' => $this->uniqueName(NetworkRoute::class, $project->id, 'Rov '.($index + 1)),
                    'route_type' => 'trench',
                    'installation_type' => 'underground',
                    'counts_as_trench' => true,
                    'duct_length_m' => $chain['length_m'],
                    'fiber_length_m' => 0,
                    'fiber_count' => 0,
                    'microduct_count' => 0,
                    'microduct_type' => null,
                    'status' => 'planned',
                    'path' => $chain['path'],
                    'note' => 'Geodetski snimak: '.$chain['code'],
                ]);
                $created['trenches']++;
            }

            // 3. Microducts as distribution routes, bound to their cabinet/ODF.
            foreach ($network['ducts'] as $duct) {
                $cabinet = $this->matchCabinet($duct, $allCabinets);
                $odf = $this->nearestWithin($allOdfs, $duct['path'][0], self::DUCT_ENDPOINT_BIND_M);
                $existing = $this->findExistingDuctRoute($project->id, $duct);
                if ($existing) {
                    $mergedPath = $this->mergeTouchingPaths($existing->path ?? [], $duct['path']);
                    $existing->update([
                        'path' => $mergedPath,
                        'duct_length_m' => $this->geometry->polylineLength($mergedPath),
                        'microduct_count' => max((int) $existing->microduct_count, (int) $duct['microduct_count']),
                        'note' => $this->appendImportNote($existing->note, $this->ductImportNote($duct)),
                    ]);

                    continue;
                }

                $route = NetworkRoute::create([
                    'project_id' => $project->id,
                    'odf_id' => $odf?->id,
                    'cabinet_id' => $cabinet?->id,
                    'from_type' => $odf ? 'odf' : null,
                    'from_id' => $odf?->id,
                    'to_type' => $cabinet ? 'cabinet' : null,
                    'to_id' => $cabinet?->id,
                    'name' => $this->uniqueName(NetworkRoute::class, $project->id, $duct['label']),
                    'route_type' => 'distribution',
                    'installation_type' => 'underground',
                    'counts_as_trench' => false,
                    'duct_length_m' => $duct['length_m'],
                    'fiber_length_m' => 0,
                    'fiber_count' => 0, // mikrocijev bez uvucenog kabla
                    'microduct_count' => $duct['microduct_count'],
                    'microduct_type' => $duct['microduct_type'],
                    'status' => 'planned',
                    'path' => $duct['path'],
                    'note' => $this->ductImportNote($duct),
                ]);
                $this->branchSync->createBranchForRoute($route);
                $created['ducts']++;
            }

            // 4. Manholes.
            foreach (collect($points)->where('kind', 'manhole') as $point) {
                $nearby = ProjectAppendixItem::where('project_id', $project->id)
                    ->where('type', 'manhole')
                    ->get()
                    ->contains(fn ($item) => $item->latitude !== null && $this->geometry->distanceMeters(
                        (float) $item->latitude, (float) $item->longitude, $point['lat'], $point['lng']
                    ) <= self::EXISTING_ELEMENT_TOLERANCE_M);
                if ($nearby) {
                    continue;
                }
                ProjectAppendixItem::create([
                    'project_id' => $project->id,
                    'type' => 'manhole',
                    'quantity' => 1,
                    'unit' => 'KOMADA',
                    'latitude' => $point['lat'],
                    'longitude' => $point['lng'],
                    'note' => 'Geodetski snimak',
                ]);
                $created['manholes']++;
            }

            // 5. Slings mark a prepared house connection — create unassigned houses.
            //    Includes "Rov +Šlinga" points (trench carrying a house tap).
            $slingPoints = collect($points)->filter(fn ($p) => $p['kind'] === 'sling' || ! empty($p['has_sling']));
            foreach ($slingPoints as $point) {
                if ($this->existsNearby(House::class, $project->id, $point['lat'], $point['lng'])) {
                    continue;
                }
                House::create([
                    'project_id' => $project->id,
                    'label' => $this->uniqueHouseLabel($project->id, 'Kuca t'.$point['point_no']),
                    'address' => $point['code'] ?: 'Geodetski snimak',
                    'status' => 'planned',
                    'latitude' => $point['lat'],
                    'longitude' => $point['lng'],
                ]);
                $created['houses']++;
            }
        });

        return $created;
    }

    private function findExistingDuctRoute(int $projectId, array $duct): ?NetworkRoute
    {
        $query = NetworkRoute::where('project_id', $projectId)
            ->where('route_type', 'distribution')
            ->where('microduct_type', $duct['microduct_type']);

        if ($duct['zo_tag'] !== null) {
            $query->where('note', 'like', '%ZO '.$duct['zo_tag'].'%');
        } elseif ($duct['color'] !== null) {
            $query->where('name', 'like', '%'.$duct['color'].'%');
        } else {
            $query->where('name', 'like', '%'.$duct['microduct_type'].'%');
        }

        foreach ($query->get() as $route) {
            if ($this->pathsTouchOrOverlap($route->path ?? [], $duct['path'])) {
                return $route;
            }
        }

        return null;
    }

    private function findExistingRouteGeometry(int $projectId, string $type, array $path): ?NetworkRoute
    {
        foreach (NetworkRoute::where('project_id', $projectId)->where('route_type', $type)->get() as $route) {
            if ($this->pathsTouchOrOverlap($route->path ?? [], $path)) {
                return $route;
            }
        }

        return null;
    }

    private function pathsTouchOrOverlap(array $existing, array $incoming): bool
    {
        if (count($existing) < 2 || count($incoming) < 2) {
            return false;
        }

        $start = $incoming[0];
        $end = $incoming[count($incoming) - 1];
        $startNear = $this->geometry->distanceToRoute($start[0], $start[1], $existing) <= self::EXISTING_ELEMENT_TOLERANCE_M;
        $endNear = $this->geometry->distanceToRoute($end[0], $end[1], $existing) <= self::EXISTING_ELEMENT_TOLERANCE_M;
        if ($startNear && $endNear) {
            return true;
        }

        foreach ($this->pathEndpoints($existing) as $a) {
            foreach ($this->pathEndpoints($incoming) as $b) {
                if ($this->geometry->distanceBetweenPoints($a, $b) <= self::EXISTING_ELEMENT_TOLERANCE_M) {
                    return true;
                }
            }
        }

        return false;
    }

    private function mergeTouchingPaths(array $existing, array $incoming): array
    {
        if (count($existing) < 2) {
            return $this->geometry->compactPath($incoming);
        }
        if (count($incoming) < 2) {
            return $this->geometry->compactPath($existing);
        }

        $variants = [
            [$existing, $incoming],
            [$existing, array_reverse($incoming)],
            [array_reverse($existing), $incoming],
            [array_reverse($existing), array_reverse($incoming)],
        ];

        foreach ($variants as [$a, $b]) {
            if ($this->geometry->distanceBetweenPoints($a[count($a) - 1], $b[0]) <= self::EXISTING_ELEMENT_TOLERANCE_M) {
                if ($this->geometry->distanceBetweenPoints($a[count($a) - 1], $b[0]) <= 0.5) {
                    array_shift($b);
                }

                return $this->geometry->compactPath(array_merge($a, $b));
            }
        }

        return $this->geometry->compactPath($existing);
    }

    private function pathEndpoints(array $path): array
    {
        return [$path[0], $path[count($path) - 1]];
    }

    private function ductImportNote(array $duct): string
    {
        return 'Geodetski snimak - mikrocijev'
            .($duct['color'] ? ' boja: '.$duct['color'] : '')
            .($duct['zo_tag'] !== null ? ' pripada: ZO '.$duct['zo_tag'] : '');
    }

    private function appendImportNote(?string $existing, string $new): string
    {
        $existing = trim((string) $existing);
        if ($existing === '') {
            return $new;
        }
        if (str_contains($existing, $new)) {
            return $existing;
        }

        return $existing."\n".$new;
    }

    // -------------------------------------------------------------------------
    // Element binding helpers
    // -------------------------------------------------------------------------

    private function matchCabinet(array $duct, $cabinets): ?Cabinet
    {
        // Prefer the explicit ZO tag from the description.
        if ($duct['zo_tag'] !== null) {
            foreach ($cabinets as $cabinet) {
                if ($this->cabinetTag($cabinet->name) === $duct['zo_tag']) {
                    return $cabinet;
                }
            }
        }

        // Otherwise the cabinet standing at either end of the duct.
        return $this->nearestWithin($cabinets, end($duct['path']), self::DUCT_ENDPOINT_BIND_M)
            ?? $this->nearestWithin($cabinets, $duct['path'][0], self::DUCT_ENDPOINT_BIND_M);
    }

    private function nearestWithin($models, array $point, float $maxMeters)
    {
        $best = null;
        $bestDistance = INF;
        foreach ($models as $model) {
            $distance = $this->geometry->distanceMeters((float) $model->latitude, (float) $model->longitude, $point[0], $point[1]);
            if ($distance < $bestDistance) {
                $bestDistance = $distance;
                $best = $model;
            }
        }

        return $bestDistance <= $maxMeters ? $best : null;
    }

    private function cabinetTag(string $name): ?string
    {
        $n = strtr(mb_strtolower($name), ['š' => 's']);
        if (preg_match('/z\s*[o0][\s\-_.]*([0-9]+(?:[.\-][0-9]+)*)/', $n, $m)) {
            return $this->normalizeZoTag($m[1]);
        }

        return null;
    }

    private function normalizeZoTag(string $raw): string
    {
        $tag = str_replace('-', '.', trim($raw, '.-_ '));
        $parts = explode('.', $tag);
        while (count($parts) > 1 && (int) end($parts) === 0) {
            array_pop($parts); // "7.00" → "7", "1.0" → "1", "4.1.00" → "4.1"
        }

        return ltrim(implode('.', $parts), '0') ?: '0';
    }

    private function cabinetLabel(string $code): string
    {
        $n = trim(preg_replace('/\s+/', ' ', $code) ?? '');
        if (preg_match('/(?:zeleni\s*ormar|z\s*[o0])[\s\-_.]*([\d.]+)?/iu', strtr(mb_strtolower($n), ['š' => 's']), $m)) {
            return 'ZO'.(isset($m[1]) && $m[1] !== '' ? ' '.rtrim($m[1], '.') : '');
        }

        return $n !== '' ? $n : 'ZO';
    }

    private function mergeOdfPoints(array $points): array
    {
        $merged = [];
        foreach (collect($points)->where('kind', 'odf') as $point) {
            foreach ($merged as $existing) {
                if ($this->geometry->distanceMeters($existing['lat'], $existing['lng'], $point['lat'], $point['lng']) <= self::ODF_MERGE_M) {
                    continue 2;
                }
            }
            $merged[] = ['code' => $point['code'], 'lat' => $point['lat'], 'lng' => $point['lng']];
        }

        return $merged;
    }

    private function existsNearby(string $model, int $projectId, float $lat, float $lng): bool
    {
        return $model::query()
            ->where('project_id', $projectId)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->get(['latitude', 'longitude'])
            ->contains(fn ($row) => $this->geometry->distanceMeters(
                (float) $row->latitude, (float) $row->longitude, $lat, $lng
            ) <= self::EXISTING_ELEMENT_TOLERANCE_M);
    }

    private function uniqueName(string $model, int $projectId, string $base): string
    {
        $base = trim($base) !== '' ? trim($base) : 'Element';
        if (! $model::query()->where('project_id', $projectId)->where('name', $base)->exists()) {
            return $base;
        }
        for ($suffix = 2; $suffix < 1000; $suffix++) {
            $candidate = "{$base}-{$suffix}";
            if (! $model::query()->where('project_id', $projectId)->where('name', $candidate)->exists()) {
                return $candidate;
            }
        }

        throw new InvalidArgumentException('Nije moguce generisati jedinstven naziv.');
    }

    private function uniqueHouseLabel(int $projectId, string $base): string
    {
        if (! House::where('project_id', $projectId)->where('label', $base)->exists()) {
            return $base;
        }
        for ($suffix = 2; $suffix < 1000; $suffix++) {
            $candidate = "{$base}-{$suffix}";
            if (! House::where('project_id', $projectId)->where('label', $candidate)->exists()) {
                return $candidate;
            }
        }

        throw new InvalidArgumentException('Nije moguce generisati jedinstvenu oznaku kuce.');
    }
}
