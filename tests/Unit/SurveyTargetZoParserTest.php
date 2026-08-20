<?php

namespace Tests\Unit;

use App\Services\SurveyPointCodeNormalizer;
use App\Services\SurveyTargetZoParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SurveyTargetZoParserTest extends TestCase
{
    #[DataProvider('explicitTargets')]
    public function test_it_normalizes_explicit_zo_targets(string $description, string $expectedTarget, string $matchedText): void
    {
        $result = $this->parser()->parse($description);

        $this->assertSame($description, $result['raw_description']);
        $this->assertSame($expectedTarget, $result['target_zo']);
        $this->assertSame($matchedText, $result['matched_text']);
        $this->assertTrue($result['explicit']);
    }

    public static function explicitTargets(): array
    {
        return [
            'space' => ['Korisnik do ZO 3', 'ZO-3', 'ZO 3'],
            'hyphen' => ['Korisnik do ZO-3', 'ZO-3', 'ZO-3'],
            'compact' => ['Korisnik do ZO3', 'ZO-3', 'ZO3'],
            'do with space' => ['do ZO 3', 'ZO-3', 'ZO 3'],
            'leading zero' => ['Do ZO-03', 'ZO-3', 'ZO-03'],
            'lowercase' => ['trasa prema zo 3', 'ZO-3', 'zo 3'],
            'multi digit space' => ['do ZO 14', 'ZO-14', 'ZO 14'],
            'multi digit hyphen' => ['do ZO-14', 'ZO-14', 'ZO-14'],
            'multi digit compact' => ['do ZO14', 'ZO-14', 'ZO14'],
            'last explicit target wins' => ['od ZO 1 do ZO-14', 'ZO-14', 'ZO-14'],
        ];
    }

    #[DataProvider('unresolvedDescriptions')]
    public function test_it_does_not_invent_a_target(string $description): void
    {
        $result = $this->parser()->parse($description);

        $this->assertSame($description, $result['raw_description']);
        $this->assertNull($result['target_zo']);
        $this->assertNull($result['matched_text']);
        $this->assertFalse($result['explicit']);
    }

    public static function unresolvedDescriptions(): array
    {
        return [
            'empty' => [''],
            'word without number' => ['vod do zelenog ormara'],
            'missing number' => ['Korisnik do ZO'],
            'embedded prefix' => ['ABZO3 nije oznaka cilja'],
            'embedded suffix' => ['ZO3X nije oznaka cilja'],
            'wrong abbreviation' => ['Korisnik do Z 3'],
            'coordinate-like text' => ['Tačka 5303, visina 303'],
        ];
    }

    private function parser(): SurveyTargetZoParser
    {
        return new SurveyTargetZoParser(new SurveyPointCodeNormalizer);
    }
}
