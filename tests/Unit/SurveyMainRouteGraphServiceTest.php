<?php

namespace Tests\Unit;

use App\Services\GeometryService;
use App\Services\SurveyMainRouteGraphService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class SurveyMainRouteGraphServiceTest extends TestCase
{
    public function test_branching_graph_uses_only_real_segments_and_keeps_edge_provenance(): void
    {
        $a = [44.0000, 18.0000];
        $b = [44.0000, 18.0001];
        $c = [44.0000, 18.0002];
        $d = [44.0000, 18.0003];
        $e = [43.9999, 18.0002];
        $f = [43.9999, 18.0003];

        $service = $this->service();
        $graph = $service->build([
            ['id' => 10, 'path' => [$a, $b, $c, $d]],
            ['id' => 20, 'path' => [$c, $e, $f]],
        ]);

        $this->assertCount(6, $graph['nodes']);
        $this->assertSame(5, $this->undirectedEdgeCount($graph));

        $pathToD = $service->shortestPath($graph, $a, $d);
        $pathToF = $service->shortestPath($graph, $a, $f);

        $this->assertSame([$a, $b, $c, $d], $pathToD['path']);
        $this->assertSame([$a, $b, $c, $e, $f], $pathToF['path']);
        $this->assertSame([10, 10, 10], $this->routeIds($pathToD));
        $this->assertSame([10, 10, 20, 20], $this->routeIds($pathToF));
        $this->assertFalse($this->hasDirectEdge($graph, $d, $f));
    }

    public function test_close_but_disconnected_routes_are_not_joined(): void
    {
        $service = $this->service();
        $graph = $service->build([
            ['id' => 1, 'path' => [[44.0, 18.0], [44.0, 18.0001]]],
            ['id' => 2, 'path' => [[44.00001, 18.0001], [44.00001, 18.0002]]],
        ]);

        $this->assertNull($service->shortestPath($graph, [44.0, 18.0], [44.00001, 18.0002]));
    }

    public function test_five_centimetre_precision_difference_merges_the_same_physical_node(): void
    {
        $service = $this->service();
        $graph = $service->build([
            ['id' => 1, 'path' => [[44.0, 18.0], [44.0, 18.0001]]],
            ['id' => 2, 'path' => [[44.0000003, 18.0001], [44.0, 18.0002]]],
        ]);

        $this->assertNotNull($service->shortestPath($graph, [44.0, 18.0], [44.0, 18.0002]));
    }

    public function test_tolerance_cannot_be_expanded_into_a_proximity_connector(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service()->build([], 0.051);
    }

    public function test_real_segment_intersection_is_split_into_a_graph_junction(): void
    {
        $service = $this->service();
        $graph = $service->build([
            ['id' => 1, 'path' => [[44.0, 18.0], [44.0, 18.0002]]],
            ['id' => 2, 'path' => [[43.9999, 18.0001], [44.0001, 18.0001]]],
        ]);

        $result = $service->shortestPath($graph, [44.0, 18.0], [44.0001, 18.0001]);

        $this->assertNotNull($result);
        $this->assertCount(3, $result['path']);
        $this->assertEqualsWithDelta(44.0, $result['path'][1][0], 1e-10);
        $this->assertEqualsWithDelta(18.0001, $result['path'][1][1], 1e-10);
        $this->assertSame([1, 2], $this->routeIds($result));
    }

    public function test_cabinet_coordinate_must_be_a_confirmed_graph_node(): void
    {
        $service = $this->service();
        $graph = $service->build([
            ['id' => 1, 'path' => [[44.0, 18.0], [44.0, 18.0001]]],
        ]);

        $this->assertNotNull($service->locateNode($graph, [44.0000003, 18.0001]));
        $this->assertNull($service->locateNode($graph, [44.00001, 18.0001]));
    }

    public function test_confirmed_entry_in_the_middle_of_a_real_segment_splits_that_edge(): void
    {
        $service = $this->service();
        $graph = $service->build([
            ['id' => 77, 'path' => [[44.0, 18.0], [44.0, 18.0002]]],
        ]);

        $result = $service->shortestPath($graph, [44.0, 18.0001], [44.0, 18.0002]);

        $this->assertNotNull($result);
        $this->assertSame([[44.0, 18.0001], [44.0, 18.0002]], $result['path']);
        $this->assertSame(77, $result['edge_sources'][0][0]['route_id']);
    }

    public function test_collinear_overlapping_trench_and_distribution_route_form_one_network_to_odo(): void
    {
        $service = $this->service();
        $entry = [44.0, 18.00005];
        $shared = [44.0, 18.0001];
        $odo = [44.0, 18.0003];
        $graph = $service->build([
            ['id' => 'trench', 'path' => [[44.0, 18.0], [44.0, 18.0002]]],
            ['id' => 'distribution', 'path' => [$shared, [44.0, 18.0002], $odo]],
        ]);

        $result = $service->shortestPath($graph, $entry, $odo);

        $this->assertNotNull($result);
        $this->assertSame($entry, $result['path'][0]);
        $this->assertSame($odo, end($result['path']));
        $this->assertContains('distribution', collect($result['edge_sources'])->flatten(1)->pluck('route_id'));
    }

    private function service(): SurveyMainRouteGraphService
    {
        return new SurveyMainRouteGraphService(new GeometryService);
    }

    private function undirectedEdgeCount(array $graph): int
    {
        return (int) (array_sum(array_map('count', $graph['edges'])) / 2);
    }

    private function routeIds(array $result): array
    {
        return array_map(fn (array $sources) => $sources[0]['route_id'], $result['edge_sources']);
    }

    private function hasDirectEdge(array $graph, array $from, array $to): bool
    {
        $fromKey = array_search($from, $graph['nodes'], true);
        $toKey = array_search($to, $graph['nodes'], true);

        return isset($graph['edges'][$fromKey][$toKey]);
    }
}
