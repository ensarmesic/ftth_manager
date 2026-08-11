<?php

namespace Tests\Unit;

use App\Services\SurveyPointCodeNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SurveyPointCodeNormalizerTest extends TestCase
{
    #[DataProvider('cabinetTags')]
    public function test_it_normalizes_cabinet_tags(string $input, string $expected): void
    {
        $this->assertSame($expected, (new SurveyPointCodeNormalizer)->cabinetTag($input));
    }

    public static function cabinetTags(): array
    {
        return [
            ['7.00', '7'],
            ['01-02', '1.02'],
            ['4.1.00', '4.1'],
            ['0', '0'],
        ];
    }

    #[DataProvider('houseDescriptions')]
    public function test_it_recognizes_house_descriptions(string $description, bool $expected): void
    {
        $this->assertSame($expected, (new SurveyPointCodeNormalizer)->isHousePoint($description));
    }

    public static function houseDescriptions(): array
    {
        return [
            ['rov do kuce', true],
            ['slinga za kucu', true],
            ['mikrodukt zo 3', false],
        ];
    }
}
