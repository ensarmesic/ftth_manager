<?php

namespace Tests\Unit;

use App\Services\SurveyPreviewQualityService;
use PHPUnit\Framework\TestCase;

class SurveyPreviewQualityServiceTest extends TestCase
{
    public function test_it_reports_blocking_survey_quality_issues(): void
    {
        $points = [
            ['point_no' => 1, 'kind' => 'trench', 'code' => '10/8', 'microduct_type' => '10/8', 'zo_tag' => null, 'lat' => 44.0, 'lng' => 18.0],
            ['point_no' => 1, 'kind' => 'other', 'code' => 'nepoznato', 'microduct_type' => null, 'zo_tag' => null, 'lat' => 44.1, 'lng' => 18.1],
        ];
        $ducts = [[
            'prepared_sling' => true,
            'cabinet_reached' => false,
        ]];

        $quality = (new SurveyPreviewQualityService)->analyze($points, $ducts);

        $this->assertSame('blocked', $quality['status']);
        $this->assertCount(3, $quality['errors']);
        $this->assertCount(1, $quality['warnings']);
        $this->assertSame([1], $quality['duplicate_point_numbers']);
        $this->assertSame([1], $quality['customer_points_without_cabinet']);
        $this->assertSame(['nepoznato'], $quality['unrecognized_codes']);
        $this->assertSame(1, $quality['unreachable_drop_routes']);
        $this->assertCount(2, $quality['issue_points']);
    }

    public function test_unreachable_drop_warns_but_does_not_block_manual_correction(): void
    {
        $quality = (new SurveyPreviewQualityService)->analyze([], [[
            'prepared_sling' => true,
            'cabinet_reached' => false,
        ]]);
        $this->assertSame('ready', $quality['status']);
        $this->assertSame([], $quality['errors']);
        $this->assertCount(1, $quality['warnings']);
        $this->assertSame(1, $quality['unreachable_drop_routes']);
    }

    public function test_it_marks_a_connected_clean_survey_as_ready(): void
    {
        $points = [[
            'point_no' => 1,
            'kind' => 'sling',
            'code' => '10/8 ZO 2',
            'microduct_type' => '10/8',
            'zo_tag' => '2',
            'lat' => 44.0,
            'lng' => 18.0,
        ]];

        $quality = (new SurveyPreviewQualityService)->analyze($points, [[
            'prepared_sling' => true,
            'cabinet_reached' => true,
        ]]);

        $this->assertSame('ready', $quality['status']);
        $this->assertSame([], $quality['errors']);
        $this->assertSame(1, $quality['complete_drop_routes']);
        $this->assertSame(0, $quality['unreachable_drop_routes']);
    }
}
