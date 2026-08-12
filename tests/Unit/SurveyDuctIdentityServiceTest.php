<?php

namespace Tests\Unit;

use App\Services\GeometryService;
use App\Services\SurveyDuctIdentityService;
use PHPUnit\Framework\TestCase;

class SurveyDuctIdentityServiceTest extends TestCase
{
    private SurveyDuctIdentityService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new SurveyDuctIdentityService(new GeometryService);
    }

    public function test_it_expands_a_coloured_bundle_into_separate_identities(): void
    {
        $identities = $this->service->identitiesAt([
            'duct_identities' => [],
            'microduct_type' => '14/10',
            'microduct_count' => 3,
            'colors' => ['zelena', 'plava'],
            'color_counts' => ['zelena' => 2, 'plava' => 1],
            'zo_tag' => '1',
            'transit' => false,
        ]);

        $this->assertSame(2, $identities['14/10|zelena|zo:1']['count']);
        $this->assertSame(1, $identities['14/10|plava|zo:1']['count']);
        $this->assertSame('1', $identities['14/10|zelena|zo:1']['tag']);
    }

    public function test_it_preserves_transit_on_an_uncoloured_identity(): void
    {
        $identities = $this->service->identitiesAt([
            'duct_identities' => [],
            'microduct_type' => '10/8',
            'microduct_count' => 1,
            'colors' => [],
            'zo_tag' => '3',
            'transit' => true,
        ]);

        $this->assertTrue($identities['10/8|zo:3']['transit']);
        $this->assertSame('10/8', $identities['10/8|zo:3']['type']);
    }

    public function test_it_formats_a_human_readable_duct_label(): void
    {
        $this->assertSame('MC 2x14/10 zelena', $this->service->label([
            'type' => '14/10',
            'color' => 'zelena',
            'tag' => null,
        ], 2));

        $this->assertSame('MC 10/8 ZO 4', $this->service->label([
            'type' => '10/8',
            'color' => null,
            'tag' => '4',
        ], 1));
    }

    public function test_it_infers_one_unambiguous_cabinet_tag_from_touching_routes(): void
    {
        $ducts = [
            [
                'key' => '10/8|anon', 'microduct_type' => '10/8', 'microduct_count' => 1,
                'color' => null, 'zo_tag' => null, 'transit' => false,
                'path' => [[44.0, 18.0], [44.0001, 18.0001]],
            ],
            [
                'key' => '10/8|zo:7', 'microduct_type' => '10/8', 'microduct_count' => 1,
                'color' => null, 'zo_tag' => '7', 'transit' => false,
                'path' => [[44.0001, 18.0001], [44.0002, 18.0002]],
            ],
        ];

        $result = $this->service->inferImplicitCabinetTags($ducts, 1.5);

        $this->assertSame('7', $result[0]['zo_tag']);
        $this->assertSame('10/8|zo:7', $result[0]['key']);
        $this->assertSame('MC 10/8 ZO 7', $result[0]['label']);
    }
}
