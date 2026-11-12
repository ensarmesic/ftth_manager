<?php

namespace Tests\Unit;

use App\Services\DuctSurveyInterpreter;
use App\Support\DuctSurvey\DuctSurveyDescription;
use PHPUnit\Framework\TestCase;

class DuctSurveyInterpreterTest extends TestCase
{
    public function test_reads_field_descriptions(): void
    {
        $zo = DuctSurveyDescription::parse('ZO 2.1-S2-ODF 3');
        $this->assertSame('zo', $zo['type']);
        $this->assertSame(['label' => '2.1', 'secondary' => 2, 'odf' => 3], $zo['zo']);
        $this->assertSame(['label' => '1', 'secondary' => null, 'odf' => 4], DuctSurveyDescription::parse('ZO 1 -odf- 4')['zo']);
        $this->assertSame('odf', DuctSurveyDescription::parse('ODF 3')['type']);

        $trench = DuctSurveyDescription::parse('Rov +MD x 2 + 14/10 mc x 2 -plava + crvena');
        $this->assertSame('main', $trench['role']);
        $this->assertSame(['md' => 2, 'crvena' => 1, 'plava' => 1], $trench['ducts']);

        $this->assertEquals(['md' => 1, 'zuta' => 1, 'plava' => 1, 'zelena' => 1, 'crvena' => 1],
            DuctSurveyDescription::parse('"Rov +MD  + 14/10   Plava +Zita Zelena Crvena"')['ducts']);
        $this->assertSame(3, DuctSurveyDescription::parse('Rov + 14 mc Plava x 2+ Zuta + crvena + Alkatwn DN 50 x 3')['alkaten']);

        $spur = DuctSurveyDescription::parse('Rov+ 14/10   Plava dolaz na stub - odlazi');
        $this->assertSame(['line', 'side', true], [$spur['type'], $spur['role'], $spur['spur']]);

        $this->assertSame('box', DuctSurveyDescription::parse('Box na stubu')['type']);
        $this->assertSame('izvod', DuctSurveyDescription::parse('Izvod 14/10 Crvena MC')['type']);
        $this->assertSame('slinga', DuctSurveyDescription::parse('Slinga plave-ide do Z1-i vrraca se')['type']);
        $this->assertArrayNotHasKey('md', DuctSurveyDescription::parse('IZ MD presječena zuta i nastiklana 14/10 Zuta')['ducts']);
    }

    public function test_follows_tubes_from_odf_through_branches_spurs_and_closures(): void
    {
        $points = [
            [1, 0, 0, 'ODF 1'],
            [2, 2, 0, 'Rov +MD + 14/10 Crvena'],
            [3, 7, 0, 'Rov +MD + 14/10 Crvena'],
            [4, 12, 0, 'Rov +MD + 14/10 Crvena'],
            // spur to a pole box, recorded out of order 5 m back
            [5, 7, 1, 'Rov+ 14/10 Crvena dolaz na stub - odlazi'],
            [6, 7, 3, 'Box na stubu'],
            [7, 17, 0, 'Rov +MD + 14/10 Crvena+Zuta'],
            [8, 17, 1, 'ZO 1-S1-ODF 1'],
            [9, 22, 0, 'Rov +MD + 14/10 Crvena'],
            [10, 27, 0, 'Rov +MD + 14/10 Crvena'],
            [11, 27, 1, 'ZO 2-S1-ODF 1'],
            // yellow leaves ZO 1 northwards, surveyed afterwards (a jump back)
            [12, 17.5, 2, 'Rov + 14/10 Zuta'],
            [13, 17.5, 7, 'Rov + 14/10 Zuta'],
            [14, 18, 7.5, 'ZO 1.1-S1-ODF 1'],
        ];
        $result = (new DuctSurveyInterpreter)->interpret(array_map(
            fn ($p) => ['point_no' => $p[0], 'x' => (float) $p[1], 'y' => (float) $p[2], 'code' => $p[3]],
            $points,
        ));

        $this->assertSame([], $result['warnings']);
        $s1 = $result['secondaries']['1|1'];
        $this->assertSame(['1', '1.1', '2'], array_column($s1, 'label'));
        $this->assertTrue(collect($s1)->every(fn ($zo) => $zo['reached']));

        $red = collect($result['flows'])->firstWhere('color', 'crvena');
        $kinds = array_map(fn ($o) => $result['nodes'][$o['node']]['kind'], $red['objects']);
        $this->assertSame(['box', 'zo', 'zo'], $kinds);

        $yellow = collect($result['flows'])->firstWhere('color', 'zuta');
        $this->assertSame('zo', $result['nodes'][$yellow['root']]['kind']);
        $this->assertSame([], $result['starts']);
    }

    public function test_understands_explicit_hints_and_reports_checks(): void
    {
        $points = [
            [1, 0, 0, 'ODF 1'],
            [2, 2, 0, 'Rov + 14/10 Crvena'],
            [3, 7, 0, 'Rov + 14/10 Crvena'],
            [4, 7, 1, 'ZO 1-S1-ODF 1'],
            [5, 12, 0, 'Rov + 14/10 Crvena'],
            [6, 17, 0, 'Rov + 14/10 Crvena'],
            [7, 90, 90, 'Rov + 14/10 Crvena pogresno'],   // marked wrong: skipped
            [8, 22, 0, 'Rov + 14/10 Crvena + Zuta iz ZO 1-S1'], // yellow comes from ZO 1
            [9, 27, 0, 'Rov + 14/10 Crvena + Zuta'],
            [10, 27, 1, 'Busenje pocetak 14/10 Zuta'],
            [11, 27, 9, 'Busenje kraj 14/10 Zuta'],
            [12, 27, 10, 'ZO 2-S1-ODF 1'],
            [15, 32, 0, 'Rov + 14/10 Crvena'],             // 13, 14 deleted
            [16, 32, 1, 'ZO 3 odf 1'],
        ];
        $result = (new DuctSurveyInterpreter)->interpret(array_map(
            fn ($p) => ['point_no' => $p[0], 'x' => (float) $p[1], 'y' => (float) $p[2], 'code' => $p[3]],
            $points,
        ));
        $messages = array_column($result['checks'], 'message');

        $this->assertSame([], $result['starts'], 'yellow is traced back to ZO 1');
        $s1 = collect($result['secondaries']['1|1'])->keyBy('label');
        $this->assertTrue($s1['2']['reached'], 'ZO 2 is reached across the road boring');
        $this->assertFalse(collect($result['nodes'])->contains(fn ($n) => in_array(7, $n['points'])));
        $this->assertTrue(collect($messages)->contains(fn ($m) => str_contains($m, 'označena kao pogrešna')));
        $this->assertTrue(collect($messages)->contains(fn ($m) => str_contains($m, 'Tačke 13–14 ne postoje')));
        $this->assertTrue(collect($messages)->contains(fn ($m) => str_contains($m, '"ZO 3 odf 1" nema krak (S)')));
    }

    public function test_a_day_without_the_odf_keeps_its_tubes(): void
    {
        // The ODF was surveyed another day: tubes cannot be traced, but are not slips.
        $points = [
            [1, 0, 0, 'Rov +MD + 14/10 Plava'],
            [2, 5, 0, 'Rov +MD + 14/10 Plava'],
            [3, 10, 0, 'Rov +MD + 14/10 Plava'],
            [4, 10, 1, 'ZO-2.2'],
            [5, 10.5, 1.2, 'ZO-2.2'],   // same closure, second point
        ];
        $result = (new DuctSurveyInterpreter)->interpret(array_map(
            fn ($p) => ['point_no' => $p[0], 'x' => (float) $p[1], 'y' => (float) $p[2], 'code' => $p[3]],
            $points,
        ));

        $this->assertEqualsCanonicalizing(['md', 'plava'], array_unique(array_column($result['flows'], 'color')));
        $this->assertCount(1, array_filter($result['nodes'], fn ($n) => $n['kind'] === 'zo'));
        $levels = array_column($result['checks'], 'level');
        $this->assertNotContains('greska', $levels);
        $this->assertNotContains('upozorenje', $levels);
        $this->assertTrue(collect($result['checks'])->contains(fn ($c) => str_contains($c['message'], 'nema ODF u snimku')));
    }

    public function test_cleans_up_survey_slips(): void
    {
        $points = [
            [100, 0, 0, 'ODF 1'],
            [101, 2, 0, 'Rov + 14/10 Plava+Crvena'],
            [102, 6, 0, 'Rov + 14/10 Plava+Crvena'],
            [103, 6, 1, 'ZO 1-S1-ODF 1'],            // beside a two-tube trench
            [104, 10, 0, 'Rov + 14/10 Plava+Crvena'],
            [105, 14, 0, 'Rov + 14/10 Plava+Crvena'],
            [106, 26, 0, 'Rov + 14/10 Plava+Crvena'], // 12 m jump: points in between deleted
            [107, 26, 1, 'Rov+ 14/10 Plava dolaz u ZO'],
            [108, 26, 2, 'ZO 2-S1-ODF 1'],            // fed by the blue spur
            [109, 30, 0, 'Rov + 14/10 Crvena'],
            [110, 17, 0.5, 'Rov + 14/10 Plava+Crvena'], // the deleted stretch, surveyed again
            [111, 20, 0.5, 'Rov + 14/10 Plava+Crvena'],
            [112, 25, 0.3, 'Rov + 14/10 Plava+Crvena'],
            [113, 40, 15, 'Rov + 14/10 Zuta'],        // a new, unconnected line 18 m away
            [114, 40, 16, 'ZO 3-S2-ODF 1'],
        ];
        $result = (new DuctSurveyInterpreter)->interpret(array_map(
            fn ($p) => ['point_no' => $p[0], 'x' => (float) $p[1], 'y' => (float) $p[2], 'code' => $p[3]],
            $points,
        ));
        $linked = function (int $a, int $b) use ($result): bool {
            foreach ($result['edges'] as $e) {
                $ends = [$result['nodes'][$e['a']]['points'], $result['nodes'][$e['b']]['points']];
                if ((in_array($a, $ends[0]) && in_array($b, $ends[1])) || (in_array($b, $ends[0]) && in_array($a, $ends[1]))) {
                    return true;
                }
            }

            return false;
        };

        $this->assertFalse($linked(105, 106), 'false shortcut over deleted points stays');
        $this->assertTrue($linked(105, 110) && $linked(112, 106));
        $this->assertFalse($linked(109, 113), 'a new line must not hang on the previous one');
        $this->assertTrue(collect($result['warnings'])->contains(fn ($w) => str_contains($w['message'], 'nije povezan')));

        $red = collect($result['flows'])->firstWhere('color', 'crvena');
        $redObjects = array_map(fn ($o) => $result['nodes'][$o['node']]['zo']['label'] ?? null, $red['objects']);
        $this->assertNotContains('1', $redObjects, 'ZO 1-S1 is on the blue tube like ZO 2-S1');
    }
}
