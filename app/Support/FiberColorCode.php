<?php

namespace App\Support;

final class FiberColorCode
{
    /** @return array<int, array{name: string, english: string, hex: string, text: string, border: string}> */
    public static function palette(): array
    {
        return [
            1 => ['name' => 'Plava', 'english' => 'Blue', 'hex' => '#2563eb', 'text' => '#ffffff', 'border' => '#1d4ed8'],
            2 => ['name' => 'Narandžasta', 'english' => 'Orange', 'hex' => '#f97316', 'text' => '#ffffff', 'border' => '#ea580c'],
            3 => ['name' => 'Zelena', 'english' => 'Green', 'hex' => '#16a34a', 'text' => '#ffffff', 'border' => '#15803d'],
            4 => ['name' => 'Smeđa', 'english' => 'Brown', 'hex' => '#92400e', 'text' => '#ffffff', 'border' => '#78350f'],
            5 => ['name' => 'Siva', 'english' => 'Slate', 'hex' => '#64748b', 'text' => '#ffffff', 'border' => '#475569'],
            6 => ['name' => 'Bijela', 'english' => 'White', 'hex' => '#ffffff', 'text' => '#0f172a', 'border' => '#94a3b8'],
            7 => ['name' => 'Crvena', 'english' => 'Red', 'hex' => '#dc2626', 'text' => '#ffffff', 'border' => '#b91c1c'],
            8 => ['name' => 'Crna', 'english' => 'Black', 'hex' => '#111827', 'text' => '#ffffff', 'border' => '#020617'],
            9 => ['name' => 'Žuta', 'english' => 'Yellow', 'hex' => '#facc15', 'text' => '#422006', 'border' => '#eab308'],
            10 => ['name' => 'Ljubičasta', 'english' => 'Violet', 'hex' => '#7c3aed', 'text' => '#ffffff', 'border' => '#6d28d9'],
            11 => ['name' => 'Ružičasta', 'english' => 'Rose', 'hex' => '#ec4899', 'text' => '#ffffff', 'border' => '#db2777'],
            12 => ['name' => 'Tirkizna', 'english' => 'Aqua', 'hex' => '#22d3ee', 'text' => '#083344', 'border' => '#06b6d4'],
        ];
    }

    /** @return array<int, array{name: string, english: string, hex: string, text: string, border: string}> */
    public static function paletteFor(string $standard): array
    {
        $palette = self::palette();
        if ($standard !== 'din_vde') {
            return $palette;
        }

        $order = [7, 3, 1, 9, 6, 5, 4, 10, 12, 8, 2, 11];

        return collect($order)->mapWithKeys(fn (int $source, int $index) => [$index + 1 => $palette[$source]])->all();
    }

    /** @return array<int, array{name: string, english: string, hex: string, text: string, border: string}> */
    public static function tubePalette(string $standard): array
    {
        return array_slice(self::paletteFor($standard), 0, 12, true);
    }

    /** @return array{number: int, tube_number: int, tube: array, position: int, color_position: int, traced: bool, fiber: array, background: string} */
    public static function describe(int $number, int $fibersPerTube = 24, string $standard = 'telcordia'): array
    {
        $number = max(1, $number);
        $fibersPerTube = in_array($fibersPerTube, [12, 24], true) ? $fibersPerTube : 24;
        $palette = self::paletteFor($standard);
        $tubeNumber = intdiv($number - 1, $fibersPerTube) + 1;
        $position = (($number - 1) % $fibersPerTube) + 1;
        $colorPosition = (($position - 1) % 12) + 1;
        $traced = $position > 12;
        $fiber = $palette[$colorPosition];

        return [
            'number' => $number,
            'tube_number' => $tubeNumber,
            'tube' => $palette[(($tubeNumber - 1) % 12) + 1],
            'position' => $position,
            'color_position' => $colorPosition,
            'traced' => $traced,
            'fiber' => $fiber,
            'background' => $traced
                ? "repeating-linear-gradient(135deg, {$fiber['hex']} 0, {$fiber['hex']} 7px, #111827 7px, #111827 10px)"
                : $fiber['hex'],
        ];
    }
}
