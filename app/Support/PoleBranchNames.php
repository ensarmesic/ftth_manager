<?php

namespace App\Support;

use App\Models\PoleBranch;
use Illuminate\Support\Collection;

/**
 * Names pole branches after their ODF and their number under that ODF:
 * "SEKUNDARNI KRAK I-2" is the second branch of ODF-01, "SEKUNDARNI KRAK I-2.1" its first side branch.
 */
final class PoleBranchNames
{
    public const PREFIX = 'SEKUNDARNI KRAK';

    /**
     * @param  Collection<int, PoleBranch>  $branches  every branch of the project(s), with `odf` loaded
     * @return array<int, array{name: string, short: string}> keyed by branch id
     */
    public static function for(Collection $branches): array
    {
        $names = [];
        foreach ($branches->groupBy('project_id') as $projectBranches) {
            $odfNumbers = self::odfNumbers($projectBranches);
            $topNumbers = [];
            $perOdf = [];
            foreach ($projectBranches->whereNull('parent_id')->sortBy('sort_order') as $top) {
                $key = $top->odf_id ?? 0;
                $perOdf[$key] = ($perOdf[$key] ?? 0) + 1;
                $topNumbers[self::topCode($top->code)] = [$perOdf[$key], $odfNumbers[$key] ?? null];
            }
            foreach ($projectBranches as $branch) {
                $top = self::topCode($branch->code);
                [$number, $odfNumber] = $topNumbers[$top] ?? [(int) substr($top, 1), null];
                $short = ($odfNumber === null ? '' : $odfNumber.'-').$number.substr($branch->code, strlen($top));
                $names[$branch->id] = ['name' => self::PREFIX.' '.$short, 'short' => $short];
            }
        }

        return $names;
    }

    /** ODF number in Roman numerals: from its name ("ODF-02-PROKOSOVICI" → "II"), otherwise its order in the project. */
    private static function odfNumbers(Collection $branches): array
    {
        $numbers = [];
        $odfs = $branches->pluck('odf')->filter()->unique('id')->sortBy('id')->values();
        foreach ($odfs as $index => $odf) {
            $number = preg_match('/\d+/', (string) $odf->name, $match) && (int) $match[0] > 0 ? (int) $match[0] : $index + 1;
            $numbers[$odf->id] = self::roman($number);
        }

        return $numbers;
    }

    public static function roman(int $number): string
    {
        $roman = '';
        foreach (['M' => 1000, 'CM' => 900, 'D' => 500, 'CD' => 400, 'C' => 100, 'XC' => 90, 'L' => 50, 'XL' => 40, 'X' => 10, 'IX' => 9, 'V' => 5, 'IV' => 4, 'I' => 1] as $symbol => $value) {
            while ($number >= $value) {
                $roman .= $symbol;
                $number -= $value;
            }
        }

        return $roman;
    }

    private static function topCode(string $code): string
    {
        return explode('.', $code, 2)[0];
    }
}
