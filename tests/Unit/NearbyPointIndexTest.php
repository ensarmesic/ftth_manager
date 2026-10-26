<?php

namespace Tests\Unit;

use App\Support\NearbyPointIndex;
use PHPUnit\Framework\TestCase;

class NearbyPointIndexTest extends TestCase
{
    public function test_points_within_radius_are_rejected_across_cell_borders(): void
    {
        $index = new NearbyPointIndex(0.5);

        $this->assertTrue($index->addIfFree(0.49, 0.0));
        // Neighbouring grid cell, 0.02 m away.
        $this->assertFalse($index->addIfFree(0.51, 0.0));
        $this->assertTrue($index->addIfFree(1.2, 0.0));
    }

    public function test_groups_never_match_each_other(): void
    {
        $index = new NearbyPointIndex(0.5);
        $index->add(10.0, 10.0, 'pole-1', 'EPSG:31276');

        $this->assertSame('pole-1', $index->find(10.1, 10.0, 'EPSG:31276'));
        $this->assertNull($index->find(10.1, 10.0, 'EPSG:31277'));
    }

    public function test_negative_coordinates_are_supported(): void
    {
        $index = new NearbyPointIndex(0.5);
        $index->add(-0.01, -0.01);

        $this->assertNotNull($index->find(0.01, 0.01));
    }
}
