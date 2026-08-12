<?php

namespace App\Services;

class SurveyChainWalker
{
    /**
     * Split an edge set into chains at junctions, forced cuts and group changes.
     *
     * @return array<int, array{nodes: int[], edges: int[]}>
     */
    public function walk(array $edgeList, ?callable $groupOf, array $forcedCutNodes = []): array
    {
        $adjacency = $this->adjacency($edgeList);
        $isCut = function (int $node) use ($adjacency, $edgeList, $groupOf, $forcedCutNodes): bool {
            if (isset($forcedCutNodes[$node])) {
                return true;
            }
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
                $next = $this->nextUnvisitedEdge($adjacency[$current], $visited);
                if ($next === null) {
                    break;
                }
                $edge = $next;
            }

            $chains[] = ['nodes' => $chainNodes, 'edges' => $chainEdges];
        };

        $this->walkFromCutsAndLoops($edgeList, $adjacency, $isCut, $visited, $walk);

        return $chains;
    }

    /**
     * Walk customer drop chains while emitting an independent path for every
     * house/reserve-loop checkpoint reached from the shared trunk.
     *
     * @return array<int, array{nodes: int[], group: mixed}>
     */
    public function walkHouseDrops(array $edgeList, array $checkpointNodes, ?callable $groupOf): array
    {
        $adjacency = $this->adjacency($edgeList);
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
                $next = $this->nextUnvisitedEdge($adjacency[$current], $visited);
                if ($next === null) {
                    break;
                }
                $edge = $next;
            }

            if (! $reachedCheckpoint) {
                $chains[] = ['nodes' => $chainNodes, 'group' => $group];
            }
        };

        $this->walkFromCutsAndLoops($edgeList, $adjacency, $isHardCut, $visited, $walk);

        return $chains;
    }

    private function adjacency(array $edgeList): array
    {
        $adjacency = [];
        foreach ($edgeList as $index => $edge) {
            $adjacency[$edge['a']][] = $index;
            $adjacency[$edge['b']][] = $index;
        }

        return $adjacency;
    }

    private function nextUnvisitedEdge(array $incidentEdges, array $visited): ?int
    {
        foreach ($incidentEdges as $candidate) {
            if (empty($visited[$candidate])) {
                return $candidate;
            }
        }

        return null;
    }

    private function walkFromCutsAndLoops(
        array $edgeList,
        array $adjacency,
        callable $isCut,
        array &$visited,
        callable $walk,
    ): void {
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

        foreach ($edgeList as $edgeIndex => $edge) {
            if (empty($visited[$edgeIndex])) {
                $walk($edge['a'], $edgeIndex);
            }
        }
    }
}
