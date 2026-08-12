<?php

namespace Tests\Unit;

use App\Services\SurveyChainWalker;
use PHPUnit\Framework\TestCase;

class SurveyChainWalkerTest extends TestCase
{
    public function test_it_splits_chains_at_a_junction(): void
    {
        $edges = [
            ['a' => 1, 'b' => 2],
            ['a' => 2, 'b' => 3],
            ['a' => 2, 'b' => 4],
        ];

        $chains = (new SurveyChainWalker)->walk($edges, null);

        $this->assertCount(3, $chains);
        $this->assertEqualsCanonicalizing([[1, 2], [2, 3], [2, 4]], array_column($chains, 'nodes'));
    }

    public function test_it_splits_a_straight_chain_when_the_group_changes(): void
    {
        $edges = [
            ['a' => 1, 'b' => 2, 'count' => 2],
            ['a' => 2, 'b' => 3, 'count' => 1],
        ];

        $chains = (new SurveyChainWalker)->walk($edges, fn (array $edge) => $edge['count']);

        $this->assertCount(2, $chains);
        $this->assertEqualsCanonicalizing([[1, 2], [2, 3]], array_column($chains, 'nodes'));
    }

    public function test_house_drop_walk_emits_an_independent_path_for_each_checkpoint(): void
    {
        $edges = [
            ['a' => 1, 'b' => 2, 'count' => 1],
            ['a' => 2, 'b' => 3, 'count' => 1],
            ['a' => 3, 'b' => 4, 'count' => 1],
        ];

        $chains = (new SurveyChainWalker)->walkHouseDrops(
            $edges,
            [3 => true, 4 => true],
            fn (array $edge) => $edge['count'],
        );

        $this->assertSame([1, 2, 3], $chains[0]['nodes']);
        $this->assertSame([1, 2, 3, 4], $chains[1]['nodes']);
        $this->assertSame(1, $chains[0]['group']);
    }

    public function test_it_keeps_a_closed_loop_as_one_chain(): void
    {
        $edges = [
            ['a' => 1, 'b' => 2],
            ['a' => 2, 'b' => 3],
            ['a' => 3, 'b' => 1],
        ];

        $chains = (new SurveyChainWalker)->walk($edges, null);

        $this->assertCount(1, $chains);
        $this->assertCount(3, $chains[0]['edges']);
        $this->assertSame($chains[0]['nodes'][0], $chains[0]['nodes'][3]);
    }
}
