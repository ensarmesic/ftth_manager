<?php

namespace Tests\Unit;

use App\Services\GeometryService;
use App\Services\SurveyCustomerRouteReconstructor;
use App\Services\SurveyImportIdentityService;
use App\Services\SurveyMainRouteGraphService;
use App\Services\SurveyPointCodeNormalizer;
use App\Services\SurveyRouteEntryPointService;
use App\Services\SurveyTargetZoParser;
use PHPUnit\Framework\TestCase;

class SurveyCustomerRouteReconstructorTest extends TestCase
{
    public function test_it_preserves_user_points_and_follows_every_real_main_route_vertex(): void
    {
        $house = [44.0002, 18.0];
        $a = [44.0001, 18.0];
        $entry = [44.0, 18.0];
        $d = [44.0, 18.0001];
        $e = [44.00005, 18.0002];
        $zo = [44.0, 18.0003];
        $result = $this->service()->reconstruct(
            'Korisnik 53 do ZO 7',
            [$house, $a, $entry],
            [['id' => 12, 'path' => [$entry, $d, $e, $zo]]],
            [['id' => 7, 'name' => 'ZO-7', 'coordinate' => $zo]],
        );

        $this->assertSame('complete', $result['status']);
        $this->assertSame('ZO-7', $result['target_zo']);
        $this->assertSame([$house, $a, $entry], $result['own_geometry']);
        $this->assertSame([$entry, $d, $e, $zo], $result['shared_main_geometry']);
        $this->assertSame([$house, $a, $entry, $d, $e, $zo], $result['full_geometry']);
        $this->assertCount(3, $result['shared_route_edges']);
        $this->assertCount(3, collect($result['shared_route_edges'])->flatten(1)->pluck('edge_id')->unique());
    }

    public function test_explicit_missing_target_never_falls_back_to_a_nearer_cabinet(): void
    {
        $result = $this->service()->reconstruct(
            'do ZO-7',
            [[44.0001, 18.0], [44.0, 18.0]],
            [['id' => 1, 'path' => [[44.0, 18.0], [44.0, 18.0001]]]],
            [['id' => 4, 'name' => 'ZO-4', 'coordinate' => [44.0, 18.0001]]],
        );

        $this->assertSame('target_not_found', $result['error_code']);
        $this->assertSame('Target ZO-7 nije pronađen.', $result['message']);
        $this->assertSame([], $result['full_geometry']);
    }

    public function test_disconnected_target_has_no_straight_line_fallback(): void
    {
        $result = $this->service()->reconstruct(
            'do ZO 7',
            [[44.0001, 18.0], [44.0, 18.0]],
            [
                ['id' => 1, 'path' => [[44.0, 18.0], [44.0, 18.0001]]],
                ['id' => 2, 'path' => [[44.001, 18.001], [44.001, 18.0011]]],
            ],
            [['id' => 7, 'name' => 'ZO-7', 'coordinate' => [44.001, 18.0011]]],
        );

        $this->assertSame('network_path_not_found', $result['error_code']);
        $this->assertSame([], $result['full_geometry']);
    }

    public function test_branch_routing_uses_explicit_zo_even_when_another_cabinet_is_closer(): void
    {
        $house = [44.0001, 18.0];
        $a = [44.0, 18.0];
        $b = [44.0, 18.0001];
        $c = [44.0, 18.0002];
        $zo4 = [44.00005, 18.0002];
        $d = [43.9999, 18.0002];
        $e = [43.9999, 18.0003];
        $zo7 = [43.9999, 18.0004];

        $result = $this->service()->reconstruct(
            'Korisnik do ZO-7',
            [$house, $a],
            [
                ['id' => 1, 'path' => [$a, $b, $c, $zo4]],
                ['id' => 2, 'path' => [$c, $d, $e, $zo7]],
            ],
            [
                ['id' => 4, 'name' => 'ZO-4', 'coordinate' => $zo4],
                ['id' => 7, 'name' => 'ZO-7', 'coordinate' => $zo7],
            ],
        );

        $this->assertSame('complete', $result['status']);
        $this->assertSame(7, $result['target_cabinet_id']);
        $this->assertSame([$house, $a, $b, $c, $d, $e, $zo7], $result['full_geometry']);
        $this->assertNotContains($zo4, $result['full_geometry']);
    }

    public function test_two_customers_reference_the_same_physical_main_edges(): void
    {
        $entry = [44.0, 18.0];
        $middle = [44.0, 18.0001];
        $zo = [44.0, 18.0002];
        $routes = [['id' => 50, 'path' => [$entry, $middle, $zo]]];
        $cabinets = [['id' => 7, 'name' => 'ZO-7', 'coordinate' => $zo]];

        $first = $this->service()->reconstruct('do ZO-7', [[44.0001, 18.0], $entry], $routes, $cabinets);
        $second = $this->service()->reconstruct('do ZO-7', [[43.9999, 18.0], $entry], $routes, $cabinets);
        $edgeIds = fn (array $result) => collect($result['shared_route_edges'])->flatten(1)->pluck('edge_id')->all();

        $this->assertSame($edgeIds($first), $edgeIds($second));
        $this->assertSame([$entry, $middle, $zo], $first['shared_main_geometry']);
        $this->assertSame([$entry, $middle, $zo], $second['shared_main_geometry']);
    }

    private function service(): SurveyCustomerRouteReconstructor
    {
        $geometry = new GeometryService;
        $parser = new SurveyTargetZoParser(new SurveyPointCodeNormalizer);

        return new SurveyCustomerRouteReconstructor(
            $parser,
            new SurveyMainRouteGraphService($geometry),
            new SurveyRouteEntryPointService($geometry),
            $geometry,
            new SurveyImportIdentityService($geometry, new SurveyPointCodeNormalizer),
        );
    }
}
