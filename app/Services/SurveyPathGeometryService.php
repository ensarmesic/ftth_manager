<?php

namespace App\Services;

class SurveyPathGeometryService
{
    public function __construct(private readonly GeometryService $geometry) {}

    /**
     * Re-join trench chains that meet end-to-end and continue in nearly the
     * same direction — a backbone crossing a branch tap reads as ONE dig, not
     * a dozen stubs. Branch chains (sharp angles) stay separate.
     */
    public function mergeCollinearChains(array $paths, float $touchM = 1.0, float $minCos = 0.5): array
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
    public function weldChainEnds(array $paths, float $toleranceM): array
    {
        $merged = true;
        while ($merged) {
            $merged = false;
            $n = count($paths);
            for ($i = 0; $i < $n && ! $merged; $i++) {
                for ($j = $i + 1; $j < $n && ! $merged; $j++) {
                    foreach ([[true, false], [true, true], [false, false], [false, true]] as [$iAtEnd, $jAtEnd]) {
                        $a = $paths[$i]['path'];
                        $b = $paths[$j]['path'];
                        $pa = $iAtEnd ? end($a) : $a[0];
                        $pb = $jAtEnd ? end($b) : $b[0];
                        if ($this->geometry->distanceBetweenPoints($pa, $pb) > $toleranceM) {
                            continue;
                        }

                        if ($paths[$i]['component'] === $paths[$j]['component']) {
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
    public function connectedComponents(array $edges): array
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
}
