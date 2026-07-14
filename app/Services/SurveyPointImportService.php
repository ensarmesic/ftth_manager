<?php

namespace App\Services;

use App\Models\Cabinet;
use App\Models\House;
use App\Models\NetworkRoute;
use App\Models\Odf;
use App\Models\Project;
use App\Models\ProjectAppendixItem;
use App\Models\SurveyPoint;
use Illuminate\Support\Collection;
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
     * @return array{kind:string,microduct_type:?string,microduct_count:int,colors:array,zo_tag:?string}
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

        // "MD" (the shared reserve/casing duct bundled alongside the coloured ones) is its
        // own physical duct that keeps running after the colours have all split away — it's
        // tracked the same way a colour is (see below) so it reads as one continuous line
        // instead of vanishing once the point stops restating a colour. In this file it's
        // always 14/10 even on later points that no longer spell that out.
        $hasReserveDuct = (bool) preg_match('/\bmd\b/', $n);
        if ($microductType === null && $hasReserveDuct) {
            $microductType = '14/10';
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
        if ($hasReserveDuct) {
            $colors['MD'] = 'MD';
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
            // A bare "sling/slinga/izvod" with no house word is a cable RESERVE loop
            // (extra coiled length for a future splice), not a customer connection —
            // 'sling' is reserved for points that actually name a house (see above).
            (bool) preg_match('/sling|slinga|izvod|sluga\b/', $n) => 'loop',
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
        // 'loop' (a reserve coil, no house) still carries the duct through it, same as a
        // plain unmarked trench point — only 'sling' (an explicit house) ends a duct.
        $ductPoints = array_values(array_filter($points, fn ($p) => in_array($p['kind'], ['trench', 'sling', 'loop'], true)));

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

        [$ductNodes, $ductEdges, $dropCheckpointNodes] = $this->buildGraph($ductPoints);

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
            $attrs = $identAttrs[$ik];
            $componentOf = $this->connectedComponents($sub);

            $byCount = [];
            if ($attrs['type'] === '10/8') {
                // Customer drops: a survey walk often passes house/loop A on its way to B.
                // Each one still needs its OWN full path back to the shared trunk/cabinet
                // (not a continuation through A's drop), so emit one path per checkpoint
                // reached, reusing the shared prefix — see walkHouseDropChains().
                foreach ($this->walkHouseDropChains($sub, $dropCheckpointNodes, fn (array $e) => $e['group']) as $chain) {
                    if (count($chain['nodes']) < 2) {
                        continue;
                    }
                    $byCount[$chain['group']][] = [
                        'path' => array_map(fn (int $node) => $ductNodes[$node], $chain['nodes']),
                        'component' => $componentOf[$chain['nodes'][0]] ?? $chain['nodes'][0],
                    ];
                }
            } else {
                foreach ($this->walkChains($sub, $ductNodes, fn (array $e) => $e['group']) as $chain) {
                    if (count($chain['nodes']) < 2) {
                        continue;
                    }
                    $chainCount = $sub[$chain['edges'][0]]['group'];
                    $byCount[$chainCount][] = [
                        'path' => array_map(fn (int $node) => $ductNodes[$node], $chain['nodes']),
                        'component' => $componentOf[$chain['nodes'][0]] ?? $chain['nodes'][0],
                    ];
                }
            }

            foreach ($byCount as $chainCount => $paths) {
                // A colour or ZO tag identifies ONE physical duct network-wide, so its chain
                // ends weld across the small unsurveyed skips where a walk jumped back near
                // an earlier junction to record another branch (see weldChainEnds() for why
                // this is safe against the multi-house case above).
                if ($attrs['color'] !== null || $attrs['tag'] !== null) {
                    $paths = $this->weldChainEnds($paths, 10.0);
                }
                foreach ($paths as $entry) {
                    $ducts[] = [
                        'key' => $ik,
                        'label' => $this->ductLabel($attrs, (int) $chainCount),
                        'microduct_type' => $attrs['type'],
                        'microduct_count' => (int) $chainCount,
                        'color' => $attrs['color'],
                        'zo_tag' => $attrs['tag'],
                        'path' => $entry['path'],
                        'length_m' => $this->geometry->polylineLength($entry['path']),
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
     * @return array{0: array<int,array<float,float>>,1: array,2: array<int,bool>} nodes, edges,
     *                                                                             and the subset of node indices that are a house or reserve loop ('sling'/'loop')
     */
    private function buildGraph(array $points): array
    {
        $count = count($points);
        if ($count < 2) {
            return [[], [], []];
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
        // Both an explicit house ('sling') and a bare reserve loop ('loop') get their own
        // dedicated drop — see walkHouseDropChains() — so both act as checkpoints here.
        $dropCheckpointNodes = [];
        for ($i = 0; $i < $count; $i++) {
            $root = $find($i);
            if (! isset($nodes[$root])) {
                $nodes[$root] = [$points[$root]['lat'], $points[$root]['lng']];
            }
            if (in_array($points[$i]['kind'], ['sling', 'loop'], true)) {
                $dropCheckpointNodes[$root] = true;
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

        return [$nodes, array_values($edges), $dropCheckpointNodes];
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
     * Weld chains of the SAME physical duct whose endpoints nearly touch — covers a survey
     * walk jumping back near an earlier junction (a few unsurveyed metres, not close enough
     * to auto-merge as one node) to record another branch, which otherwise reads as a
     * floating, disconnected fragment instead of part of the same network.
     *
     * Only welds chains from DIFFERENT connected components (see connectedComponents()) —
     * chains from the SAME walk (e.g. several houses sharing a prefix, see
     * walkHouseDropChains) already share a real, deliberate common point and must never be
     * spliced into each other.
     *
     * @param  array<int, array{path: array, component: int}>  $paths
     * @return array<int, array{path: array, component: int}>
     */
    private function weldChainEnds(array $paths, float $toleranceM): array
    {
        $merged = true;
        while ($merged) {
            $merged = false;
            $n = count($paths);
            for ($i = 0; $i < $n && ! $merged; $i++) {
                for ($j = $i + 1; $j < $n && ! $merged; $j++) {
                    if ($paths[$i]['component'] === $paths[$j]['component']) {
                        continue;
                    }
                    foreach ([[true, false], [true, true], [false, false], [false, true]] as [$iAtEnd, $jAtEnd]) {
                        $a = $paths[$i]['path'];
                        $b = $paths[$j]['path'];
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
                        $paths[$i] = ['path' => array_merge($first, $second), 'component' => $paths[$i]['component']];
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
     * Union-find over the node ids touched by $edges.
     *
     * @param  array  $edges  each ['a' => nodeId, 'b' => nodeId, ...]
     * @return array<int,int> node id => connected-component root id
     */
    private function connectedComponents(array $edges): array
    {
        $parent = [];
        $find = function (int $x) use (&$parent, &$find): int {
            if (! isset($parent[$x])) {
                $parent[$x] = $x;
            }

            return $parent[$x] === $x ? $x : ($parent[$x] = $find($parent[$x]));
        };
        foreach ($edges as $edge) {
            $parent[$find($edge['a'])] = $find($edge['b']);
        }

        $components = [];
        foreach ($edges as $edge) {
            $components[$edge['a']] = $find($edge['a']);
            $components[$edge['b']] = $find($edge['b']);
        }

        return $components;
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

    /**
     * Walk a customer-drop (10/8) identity's subgraph, emitting one path PER checkpoint
     * (house or reserve loop) it reaches — each path runs from the walk's true origin (a
     * junction, i.e. the shared trunk/cabinet end) up to and including that checkpoint,
     * reusing whatever an earlier one's path already covered. A survey often keeps walking
     * straight past house/loop A to reach house/loop B; without this, B's drop would start
     * at A instead of running independently back to the trunk. Still hard-cuts on
     * structural junctions and on $groupOf changes (count changes), same as walkChains —
     * the two just diverge on whether reaching a checkpoint stops the walk (walkChains) or
     * only checkpoints it.
     *
     * @param  array  $edgeList  each ['a' => nodeId, 'b' => nodeId, ...]
     * @param  array<int,bool>  $checkpointNodes  node ids that are a house or reserve loop
     * @param  callable|null  $groupOf  fn(edge): scalar
     * @return array<int, array{nodes: int[], group: mixed}>
     */
    private function walkHouseDropChains(array $edgeList, array $checkpointNodes, ?callable $groupOf): array
    {
        $adjacency = [];
        foreach ($edgeList as $index => $edge) {
            $adjacency[$edge['a']][] = $index;
            $adjacency[$edge['b']][] = $index;
        }

        $isHardCut = function (int $node) use ($adjacency, $edgeList, $groupOf): bool {
            $incident = $adjacency[$node] ?? [];
            if (count($incident) !== 2) {
                return true;
            }

            return $groupOf !== null
                && $groupOf($edgeList[$incident[0]]) !== $groupOf($edgeList[$incident[1]]);
        };

        $visited = [];
        $chains = [];

        $walk = function (int $startNode, int $firstEdge) use (&$visited, $adjacency, $edgeList, $isHardCut, $checkpointNodes, $groupOf, &$chains): void {
            $chainNodes = [$startNode];
            $current = $startNode;
            $edge = $firstEdge;
            $group = $groupOf !== null ? $groupOf($edgeList[$edge]) : null;
            $reachedCheckpoint = false;

            while (true) {
                $visited[$edge] = true;
                $current = $edgeList[$edge]['a'] === $current ? $edgeList[$edge]['b'] : $edgeList[$edge]['a'];
                $chainNodes[] = $current;

                if (isset($checkpointNodes[$current])) {
                    $chains[] = ['nodes' => $chainNodes, 'group' => $group];
                    $reachedCheckpoint = true;
                }

                if ($isHardCut($current)) {
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

            // No house/loop anywhere on this walk (not yet surveyed to a customer, or a
            // plain ZO-tagged run) — keep it as one duct, same as walkChains would.
            if (! $reachedCheckpoint) {
                $chains[] = ['nodes' => $chainNodes, 'group' => $group];
            }
        };

        foreach (array_keys($adjacency) as $node) {
            if (! $isHardCut($node)) {
                continue;
            }
            foreach ($adjacency[$node] as $edgeIndex) {
                if (empty($visited[$edgeIndex])) {
                    $walk($node, $edgeIndex);
                }
            }
        }
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
        // Same reasoning as houses below: a cabinet point from THIS batch isn't a real
        // Cabinet row yet either, so it's added as a stand-in (id=null) alongside real DB
        // cabinets — otherwise the most common case (one file with both the ZO points and
        // its ducts) would preview every duct as unmatched, then bind correctly on confirm().
        $cabinets = Cabinet::where('project_id', $project->id)->whereNotNull('latitude')->get()
            ->concat(
                collect($points)->where('kind', 'cabinet')->map(fn ($p) => (object) [
                    'id' => null,
                    'name' => $this->cabinetLabel($p['code']),
                    'latitude' => $p['lat'],
                    'longitude' => $p['lng'],
                ])
            );
        $odfs = Odf::where('project_id', $project->id)->whereNotNull('latitude')->get();
        // Sling points from THIS batch aren't persisted as House rows yet (preview never
        // writes), so they're added as house-shaped stand-ins alongside real DB houses —
        // otherwise a duct ending at a brand new house would preview as non-drop and then
        // flip to 'drop' on confirm(), where houses are created before ducts.
        $houseCandidates = House::where('project_id', $project->id)->whereNotNull('latitude')->get()
            ->concat(
                collect($points)
                    ->where('kind', 'sling')
                    ->map(fn ($p) => (object) ['id' => null, 'latitude' => $p['lat'], 'longitude' => $p['lng']])
            );

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
            'ducts' => collect($network['ducts'])->map(function (array $duct) use ($cabinets, $odfs, $houseCandidates) {
                $binding = $this->resolveDuctBinding($duct, $cabinets, $odfs, $houseCandidates);

                return [
                    'key' => $duct['key'],
                    'label' => $duct['label'],
                    'length_m' => $duct['length_m'],
                    'color' => $duct['color'],
                    'zo_tag' => $duct['zo_tag'],
                    'route_type' => $binding['route_type'],
                    'matched_cabinet_id' => $binding['cabinet']?->id,
                    'matched_cabinet_name' => $binding['cabinet']?->name,
                    'match_confidence' => $binding['match_confidence'],
                    'candidates' => $binding['candidates'],
                ];
            })->values()->all(),
            'cabinets' => collect($points)->where('kind', 'cabinet')->map(fn ($p) => ['code' => $p['code'], 'lat' => $p['lat'], 'lng' => $p['lng']])->values()->all(),
            'odfs' => $this->mergeOdfPoints($points),
            'manholes' => collect($points)->where('kind', 'manhole')->count(),
            'houses' => collect($points)->where('kind', 'sling')->count(),
            'unrecognized_codes' => collect($points)->where('kind', 'other')->pluck('code')->filter()->unique()->values()->all(),
            'bounds' => [
                'lat' => [collect($points)->min('lat'), collect($points)->max('lat')],
                'lng' => [collect($points)->min('lng'), collect($points)->max('lng')],
            ],
        ];
    }

    /**
     * @param  array<string,int>  $cabinetOverrides  duct key => cabinet id, from a user-reviewed preview
     */
    public function confirm(Project $project, string $contents, string $filename = '', array $cabinetOverrides = []): array
    {
        $points = $this->parse($contents);
        if (count($points) < 1) {
            throw new InvalidArgumentException('Fajl ne sadrzi nijednu prepoznatljivu tacku.');
        }

        $batch = sha1($contents);
        if (SurveyPoint::where('project_id', $project->id)->where('import_batch', $batch)->exists()) {
            throw new InvalidArgumentException('Ovaj fajl je vec uvezen u ovaj projekat.');
        }

        $created = ['points' => 0, 'trenches' => 0, 'ducts' => 0, 'cabinets' => 0, 'odfs' => 0, 'manholes' => 0, 'borings' => 0, 'splices' => 0, 'loops' => 0, 'houses' => 0];

        DB::transaction(function () use ($project, $points, $batch, $filename, $cabinetOverrides, &$created): void {
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
                    'import_batch' => $batch,
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
                    'import_batch' => $batch,
                ]);
                $created['odfs']++;
            }
            $allOdfs = Odf::where('project_id', $project->id)->whereNotNull('latitude')->get();

            // 2. Slings (points that explicitly name a house) mark a prepared house
            //    connection — create unassigned houses BEFORE ducts, so a duct ending at
            //    one can be bound and typed as a 'drop'. A bare reserve loop ('loop' kind,
            //    no house named) does NOT create a house — it's just a coiled cable reserve.
            $slingPoints = collect($points)->where('kind', 'sling');
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
                    'import_batch' => $batch,
                ]);
                $created['houses']++;
            }
            $allHouses = House::where('project_id', $project->id)->whereNotNull('latitude')->get();

            $network = $this->buildNetwork($points);

            // buildNetwork() already resolved this file's own topology (e.g. which branches
            // are genuinely separate at a junction) — findExisting*() must never second-guess
            // that by merging two routes THIS SAME call just created into each other just
            // because they happen to touch at a shared point. It only exists to continue a
            // route from an EARLIER, separate import, so freshly-created ids are excluded.
            $freshRouteIds = [];

            // 3. Physical trenches.
            foreach ($network['trenches'] as $index => $chain) {
                $existing = $this->findExistingRouteGeometry($project->id, 'trench', $chain['path'], $freshRouteIds);
                if ($existing) {
                    $mergedPath = $this->mergeTouchingPaths($existing->path ?? [], $chain['path']);
                    $existing->update([
                        'path' => $mergedPath,
                        'duct_length_m' => $this->geometry->polylineLength($mergedPath),
                        'note' => $this->appendImportNote($existing->note, 'Geodetski snimak: '.$chain['code']),
                    ]);

                    continue;
                }

                $trench = NetworkRoute::create([
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
                    'import_batch' => $batch,
                ]);
                $freshRouteIds[] = $trench->id;
                $created['trenches']++;
            }

            // 4. Microducts as routes, bound to their cabinet/ODF/house and typed by topology.
            foreach ($network['ducts'] as $duct) {
                $binding = $this->resolveDuctBinding($duct, $allCabinets, $allOdfs, $allHouses, $cabinetOverrides);
                $cabinet = $binding['cabinet'];
                $odf = $binding['odf'];
                $house = $binding['house'];
                $routeType = $binding['route_type'];

                $existing = $this->findExistingDuctRoute($project->id, $duct, $routeType, $house?->id, $freshRouteIds);
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

                // A drop always runs cabinet(ODO) -> house; feeder/distribution keep the
                // existing odf -> cabinet wiring, only route_type changes between them.
                [$fromType, $fromId, $toType, $toId] = $routeType === 'drop'
                    ? ['cabinet', $cabinet?->id, 'house', $house?->id]
                    : [$odf ? 'odf' : null, $odf?->id, $cabinet ? 'cabinet' : null, $cabinet?->id];

                $route = NetworkRoute::create([
                    'project_id' => $project->id,
                    'odf_id' => $odf?->id,
                    'cabinet_id' => $cabinet?->id,
                    'from_type' => $fromType,
                    'from_id' => $fromId,
                    'to_type' => $toType,
                    'to_id' => $toId,
                    'name' => $this->uniqueName(NetworkRoute::class, $project->id, $duct['label']),
                    'route_type' => $routeType,
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
                    'import_batch' => $batch,
                ]);
                $freshRouteIds[] = $route->id;
                $this->branchSync->createBranchForRoute($route);
                $created['ducts']++;
            }

            // 5. Appendix-item point kinds: manholes, borings (FI 130), splices, reserve loops.
            $created['manholes'] = $this->createAppendixPointsFromSurvey($project->id, $points, 'manhole', 'manhole', $batch);
            $created['borings'] = $this->createAppendixPointsFromSurvey($project->id, $points, 'boring', 'boring_fi_130', $batch);
            $created['splices'] = $this->createAppendixPointsFromSurvey($project->id, $points, 'splice', 'splice', $batch);
            $created['loops'] = $this->createAppendixPointsFromSurvey($project->id, $points, 'loop', 'loop', $batch);
        });

        return $created;
    }

    /**
     * Remove everything a geodetic survey import created in this project — routes,
     * cabinets, ODFs, houses, appendix items (all tagged with an `import_batch` at
     * creation time, never on a merge/extend of a pre-existing route — see confirm()),
     * plus all raw survey_points — so the same TXT file can be re-imported. Elements the
     * user drew manually are never tagged and are therefore never touched here, even if a
     * later import extended one of their routes.
     *
     * @return array<string,int> counts removed, keyed like confirm()'s $created
     */
    public function clearImportedData(Project $project): array
    {
        $removed = ['points' => 0, 'trenches' => 0, 'ducts' => 0, 'cabinets' => 0, 'odfs' => 0, 'manholes' => 0, 'borings' => 0, 'splices' => 0, 'loops' => 0, 'houses' => 0];

        DB::transaction(function () use ($project, &$removed): void {
            $routes = NetworkRoute::where('project_id', $project->id)->whereNotNull('import_batch')->get();
            foreach ($routes as $route) {
                $removed[$route->route_type === 'trench' ? 'trenches' : 'ducts']++;
                $this->branchSync->deleteRouteWithBranch($route);
            }

            $removed['cabinets'] = Cabinet::where('project_id', $project->id)->whereNotNull('import_batch')->count();
            Cabinet::where('project_id', $project->id)->whereNotNull('import_batch')->delete();

            $removed['odfs'] = Odf::where('project_id', $project->id)->whereNotNull('import_batch')->count();
            Odf::where('project_id', $project->id)->whereNotNull('import_batch')->delete();

            $removed['houses'] = House::where('project_id', $project->id)->whereNotNull('import_batch')->count();
            House::where('project_id', $project->id)->whereNotNull('import_batch')->delete();

            foreach (['manhole', 'boring_fi_130', 'splice', 'loop'] as $appendixType) {
                $key = match ($appendixType) {
                    'manhole' => 'manholes',
                    'boring_fi_130' => 'borings',
                    'splice' => 'splices',
                    'loop' => 'loops',
                };
                $removed[$key] = ProjectAppendixItem::where('project_id', $project->id)
                    ->where('type', $appendixType)->whereNotNull('import_batch')->count();
                ProjectAppendixItem::where('project_id', $project->id)
                    ->where('type', $appendixType)->whereNotNull('import_batch')->delete();
            }

            $removed['points'] = SurveyPoint::where('project_id', $project->id)->count();
            SurveyPoint::where('project_id', $project->id)->delete();
        });

        return $removed;
    }

    /**
     * @param  int[]  $excludeIds  routes created earlier in THIS SAME confirm() call — never
     *                             a match candidate, see the comment where $freshRouteIds is built
     */
    private function findExistingDuctRoute(int $projectId, array $duct, string $routeType, ?int $houseId, array $excludeIds): ?NetworkRoute
    {
        $query = NetworkRoute::where('project_id', $projectId)
            ->where('route_type', $routeType)
            ->where('microduct_type', $duct['microduct_type'])
            ->whereNotIn('id', $excludeIds);

        if ($routeType === 'drop' && $houseId !== null) {
            // Neighbouring houses on the same trunk now get independent, overlapping
            // full-length paths (see walkHouseDropChains) — match strictly by destination
            // house rather than geometry, so house B's drop is never merged into house A's
            // just because it shares that prefix.
            return $query->where('to_type', 'house')->where('to_id', $houseId)->first();
        }

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

    /**
     * @param  int[]  $excludeIds  routes created earlier in THIS SAME confirm() call
     */
    private function findExistingRouteGeometry(int $projectId, string $type, array $path, array $excludeIds): ?NetworkRoute
    {
        foreach (NetworkRoute::where('project_id', $projectId)->where('route_type', $type)->whereNotIn('id', $excludeIds)->get() as $route) {
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

    /**
     * Resolve which cabinet/ODF/house a duct binds to, and the route_type that follows from
     * that binding. Shared by preview() (read-only display) and confirm() (persists), so the
     * two never disagree about what an import will do.
     *
     * @param  Collection  $cabinets  Cabinet models, or cabinet-shaped stand-ins with id=null
     * @param  Collection  $odfs
     * @param  Collection  $houses  House models, or house-shaped stand-ins with id=null
     * @param  array<string,int>  $cabinetOverrides  duct key => cabinet id, from a reviewed preview
     * @return array{cabinet:?object,odf:?Odf,house:?object,route_type:string,match_confidence:string,candidates:array}
     */
    private function resolveDuctBinding(array $duct, $cabinets, $odfs, $houses, array $cabinetOverrides = []): array
    {
        $house = $this->nearestWithin($houses, end($duct['path']), self::EXISTING_ELEMENT_TOLERANCE_M)
            ?? $this->nearestWithin($houses, $duct['path'][0], self::EXISTING_ELEMENT_TOLERANCE_M);
        $odf = $this->nearestWithin($odfs, $duct['path'][0], self::DUCT_ENDPOINT_BIND_M);

        $cabinet = null;
        $matchConfidence = 'none';
        $candidates = [];

        if (isset($cabinetOverrides[$duct['key']])) {
            $cabinet = $cabinets->firstWhere('id', (int) $cabinetOverrides[$duct['key']]);
            $matchConfidence = $cabinet ? 'manual' : 'none';
        } elseif ($duct['zo_tag'] !== null) {
            // Search real cabinets first — an existing, already-named cabinet is a firmer
            // match than a same-batch stand-in that merely happens to share a ZO tag.
            $cabinet = $cabinets->first(fn ($c) => $c->id !== null && $this->cabinetTag($c->name) === $duct['zo_tag'])
                ?? $cabinets->first(fn ($c) => $this->cabinetTag($c->name) === $duct['zo_tag']);
            $matchConfidence = $cabinet ? 'exact' : 'none';
        }

        if ($cabinet === null) {
            $start = $duct['path'][0];
            $end = end($duct['path']);
            $nearby = $cabinets
                ->map(fn ($c) => ['cabinet' => $c, 'distance_m' => min(
                    $this->geometry->distanceMeters((float) $c->latitude, (float) $c->longitude, $end[0], $end[1]),
                    $this->geometry->distanceMeters((float) $c->latitude, (float) $c->longitude, $start[0], $start[1]),
                )])
                ->filter(fn (array $row) => $row['distance_m'] <= self::DUCT_ENDPOINT_BIND_M)
                ->sortBy('distance_m')
                ->values();

            if ($nearby->isNotEmpty()) {
                $cabinet = $nearby->first()['cabinet'];
                $matchConfidence = 'ambiguous';
                // Only cabinets that already have a real id can be picked from the review
                // UI — a same-batch stand-in has no id to send back as an override yet.
                $candidates = $nearby->filter(fn (array $row) => $row['cabinet']->id !== null)
                    ->map(fn (array $row) => [
                        'id' => $row['cabinet']->id,
                        'name' => $row['cabinet']->name,
                        'distance_m' => round($row['distance_m'], 1),
                    ])->values()->all();
            }
        }

        $routeType = match (true) {
            $house !== null => 'drop',
            $odf !== null => 'feeder',
            default => 'distribution',
        };

        return [
            'cabinet' => $cabinet,
            'odf' => $odf,
            'house' => $house,
            'route_type' => $routeType,
            'match_confidence' => $matchConfidence,
            'candidates' => $candidates,
        ];
    }

    /**
     * Persist a classified survey `kind` (manhole/boring/splice) as ProjectAppendixItem rows,
     * skipping any within EXISTING_ELEMENT_TOLERANCE_M of an already-stored item of the same type.
     * A survey point only records a location, never a measured length — boring length_m stays
     * null (quantity 0) so it doesn't corrupt the report's length-based BOM total.
     */
    private function createAppendixPointsFromSurvey(int $projectId, array $points, string $kind, string $appendixType, string $batch): int
    {
        $existing = ProjectAppendixItem::where('project_id', $projectId)->where('type', $appendixType)->get(['latitude', 'longitude']);
        $created = 0;

        foreach (collect($points)->where('kind', $kind) as $point) {
            $nearby = $existing->contains(fn ($item) => $item->latitude !== null && $this->geometry->distanceMeters(
                (float) $item->latitude, (float) $item->longitude, $point['lat'], $point['lng']
            ) <= self::EXISTING_ELEMENT_TOLERANCE_M);
            if ($nearby) {
                continue;
            }

            $existing->push(ProjectAppendixItem::create([
                'project_id' => $projectId,
                'type' => $appendixType,
                'quantity' => $appendixType === 'boring_fi_130' ? 0 : 1,
                'unit' => $appendixType === 'boring_fi_130' ? 'metara' : 'KOMADA',
                'latitude' => $point['lat'],
                'longitude' => $point['lng'],
                'note' => 'Geodetski snimak',
                'import_batch' => $batch,
            ]));
            $created++;
        }

        return $created;
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
