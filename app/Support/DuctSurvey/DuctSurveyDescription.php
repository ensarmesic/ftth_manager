<?php

namespace App\Support\DuctSurvey;

/**
 * Reads one free-hand field description from the "duct composition" survey
 * format, where every trench point states WHAT lies in the trench:
 *
 *   "Rov +MD + 14/10 Crvena+Zuta"       trench point: microduct MD + two 14/10 tubes
 *   "Rov+ 14/10 Plava dolaz na stub"    spur: the tube leaves the trench to a pole
 *   "ZO 2.1-S2-ODF 3"                   closure 2.1 on secondary S2 of ODF 3
 *   "Box na stubu"                      pole with a splitter box
 *   "Izvod 14/10 Crvena MC"             tube end left for a later continuation
 *
 * MD is a separate microduct (4 x 12/10 inside); 14/10 tubes run beside it
 * and are identified by colour.
 */
final class DuctSurveyDescription
{
    /** Main trench point: part of the walked line. */
    public const ROLE_MAIN = 'main';

    /** Spur, object or marker: hangs off the line, never continues it. */
    public const ROLE_SIDE = 'side';

    private const COLOR_STEMS = [
        'crven' => 'crvena',
        'zut' => 'zuta',
        'zit' => 'zuta', // frequent field typo ("Zita")
        'plav' => 'plava',
        'zelen' => 'zelena',
        'bijel' => 'bijela',
        'bjel' => 'bijela',
        'narand' => 'narandzasta',
        'ljubic' => 'ljubicasta',
        'siv' => 'siva',
        'smedj' => 'smedja',
        'tirkiz' => 'tirkizna',
    ];

    /**
     * @return array{
     *   type: string, role: string, ducts: array<string,int>, alkaten: int,
     *   spur: bool, terminates: bool, continues: bool, from_md: bool,
     *   odf_no: ?int, zo: ?array{label:string,secondary:?int,odf:?int}, secondary: ?int,
     *   from_zo: ?array{label:string,secondary:?int,odf:?int}, ignored: bool, count_missing: bool
     * }
     */
    public static function parse(string $description): array
    {
        $n = self::normalize($description);

        $result = [
            'type' => 'note',
            'role' => self::ROLE_SIDE,
            'ducts' => self::ducts($n),
            'alkaten' => self::alkaten($n),
            'spur' => str_contains($n, 'dolaz'),
            'terminates' => (bool) preg_match('/zavrsav/', $n),
            'continues' => (bool) preg_match('/za\s+nastavak|produzav/', $n),
            'from_md' => (bool) preg_match('/\biz\s+md\b/', $n),
            'odf_no' => null,
            'zo' => null,
            // "Spojnica 14/10 plave-S3": the secondary the spliced tube belongs to.
            'secondary' => preg_match('/-\s*s\s*(\d+)\b/', $n, $sm) ? (int) $sm[1] : null,
            // "... 14/10 Zuta iz ZO 2-S2": the tube starts in that closure.
            'from_zo' => self::fromZo($n),
            // A point the surveyor marked as wrong ("Pogresno", "pogresna tacka") is skipped.
            'ignored' => (bool) preg_match('/\bpogresn/', $n),
            // "14/10 mc x -plava": a count sign without the count.
            'count_missing' => (bool) preg_match('/\bx\b(?!\s*\d)/', $n),
        ];

        // "ZO 2.1-S2-ODF 3", "ZO-2.2", "ZO 1 -odf- 4"
        if (preg_match('/^zo\s*-?\s*(\d+(?:\.\d+)*)\s*-?\s*(?:s\s*(\d+)\s*-?\s*)?(?:odf\s*-?\s*(\d+))?/', $n, $m, PREG_UNMATCHED_AS_NULL)) {
            $result['type'] = 'zo';
            $result['zo'] = [
                'label' => $m[1],
                'secondary' => isset($m[2]) ? (int) $m[2] : null,
                'odf' => isset($m[3]) ? (int) $m[3] : null,
            ];
            $result['ducts'] = [];

            return $result;
        }
        if (preg_match('/^odf\s*-?\s*(\d+)/', $n, $m)) {
            $result['type'] = 'odf';
            $result['odf_no'] = (int) $m[1];
            $result['ducts'] = [];

            return $result;
        }

        $isTrench = (bool) preg_match('/^(rov|rob)\b|^rov\+/', $n);
        $result['type'] = match (true) {
            (bool) preg_match('/\bsaht\b/', $n) => 'manhole',
            str_contains($n, 'box') => 'box',
            str_contains($n, 'spojnic') => 'splice',
            str_contains($n, 'busenje') => 'boring',
            ! $isTrench && (bool) preg_match('/\bslinga?\b/', $n) => 'slinga',
            ! $isTrench && str_contains($n, 'izvod') => 'izvod',
            $isTrench || $result['spur'] => 'line',
            default => 'note',
        };

        if ($result['type'] === 'line' && ! $result['spur']) {
            $result['role'] = self::ROLE_MAIN;
        }
        // A boring keeps its tubes ("Busenje pocetak 14/10 Zuta"): they cross the road.
        if (in_array($result['type'], ['manhole', 'box'], true)) {
            $result['ducts'] = [];
        }

        return $result;
    }

    /** @return array{label:string,secondary:?int,odf:?int}|null */
    private static function fromZo(string $n): ?array
    {
        if (! preg_match('/\biz\s+(?:zo|z)\s*-?\s*(\d+(?:\.\d+)*)(?:\s*-?\s*s\s*(\d+))?(?:\s*-?\s*odf\s*-?\s*(\d+))?/', $n, $m, PREG_UNMATCHED_AS_NULL)) {
            return null;
        }

        return [
            'label' => $m[1],
            'secondary' => isset($m[2]) ? (int) $m[2] : null,
            'odf' => isset($m[3]) ? (int) $m[3] : null,
        ];
    }

    /** "MD" or "14/10 žuta" — a duct key as people write it. */
    public static function tubeLabel(string $tube): string
    {
        $display = ['zuta' => 'žuta', 'narandzasta' => 'narandžasta', 'ljubicasta' => 'ljubičasta', 'smedja' => 'smeđa'];

        return $tube === 'md' ? 'MD' : '14/10 '.($display[$tube] ?? $tube);
    }

    public static function normalize(string $description): string
    {
        $n = mb_strtolower(trim($description));
        $n = strtr($n, ['š' => 's', 'ž' => 'z', 'č' => 'c', 'ć' => 'c', 'đ' => 'dj', '"' => '', "'" => '']);

        return trim((string) preg_replace('/\s+/', ' ', $n));
    }

    /** @return array<string,int> duct key ('md' or a colour) => tube count */
    private static function ducts(string $n): array
    {
        $ducts = [];
        // "Zuta X2 iz MD" names yellow tubes taken OUT of the MD, not the MD itself.
        if (preg_match_all('/(?<!iz )\bmd\b(?:\s*x\s*(\d+))?/', $n, $m, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL)) {
            foreach ($m as $match) {
                $ducts['md'] = max($ducts['md'] ?? 0, isset($match[1]) ? (int) $match[1] : 1);
            }
        }
        foreach (self::COLOR_STEMS as $stem => $color) {
            if (! preg_match_all('/\b'.$stem.'[a-z]*(?:\s*x\s*(\d+))?/', $n, $m, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL)) {
                continue;
            }
            foreach ($m as $match) {
                $ducts[$color] = ($ducts[$color] ?? 0) + (isset($match[1]) ? (int) $match[1] : 1);
            }
        }

        return $ducts;
    }

    private static function alkaten(string $n): int
    {
        if (! preg_match('/alkat[a-z]*(?:\s*dn\s*\d+)?(?:\s*x\s*(\d+))?/', $n, $m, PREG_UNMATCHED_AS_NULL)) {
            return 0;
        }

        return isset($m[1]) ? (int) $m[1] : 1;
    }
}
