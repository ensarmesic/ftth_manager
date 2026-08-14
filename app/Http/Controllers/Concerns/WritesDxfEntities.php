<?php

namespace App\Http\Controllers\Concerns;

trait WritesDxfEntities
{
    private function dxfCircle(float $x, float $y, float $radius, string $layer, int $color): array
    {
        return ['0', 'CIRCLE', '8', $layer, '62', (string) $color, '10', (string) $x, '20', (string) $y, '30', '0', '40', (string) $radius];
    }

    private function dxfCircleDashed(float $x, float $y, float $radius, string $layer, int $color, float $ltScale = 4.0): array
    {
        return ['0', 'CIRCLE', '8', $layer, '62', (string) $color, '6', 'DASHED', '48', (string) $ltScale, '10', (string) $x, '20', (string) $y, '30', '0', '40', (string) $radius];
    }

    private function dxfSolid(float $x1, float $y1, float $x2, float $y2, float $x3, float $y3, float $x4, float $y4, string $layer, int $color): array
    {
        return [
            '0', 'SOLID', '8', $layer, '62', (string) $color,
            '10', (string) $x1, '20', (string) $y1, '30', '0',
            '11', (string) $x2, '21', (string) $y2, '31', '0',
            '12', (string) $x3, '22', (string) $y3, '32', '0',
            '13', (string) $x4, '23', (string) $y4, '33', '0',
        ];
    }

    private function dxfRect(float $x1, float $y1, float $x2, float $y2, string $layer, int $color): array
    {
        return [
            ...$this->dxfLine($x1, $y1, $x2, $y1, $layer, $color),
            ...$this->dxfLine($x2, $y1, $x2, $y2, $layer, $color),
            ...$this->dxfLine($x2, $y2, $x1, $y2, $layer, $color),
            ...$this->dxfLine($x1, $y2, $x1, $y1, $layer, $color),
        ];
    }

    private function dxfTextRotated(float $x, float $y, string $text, string $layer, int $color, float $height, float $angle): array
    {
        return [
            '0', 'TEXT', '8', $layer, '62', (string) $color,
            '7', 'FTTH',
            '10', (string) $x, '20', (string) $y, '30', '0',
            '40', (string) $height,
            '50', (string) $angle,
            '1', $this->dxfSafeText($text),
        ];
    }

    private function dxfLine(float $x1, float $y1, float $x2, float $y2, string $layer, int $color): array
    {
        return ['0', 'LINE', '8', $layer, '62', (string) $color, '10', (string) $x1, '20', (string) $y1, '30', '0', '11', (string) $x2, '21', (string) $y2, '31', '0'];
    }

    private function dxfText(float $x, float $y, string $text, string $layer, int $color, float $height = 3.0): array
    {
        return [
            '0', 'TEXT', '8', $layer, '62', (string) $color,
            '7', 'FTTH',
            '10', (string) $x, '20', (string) $y, '30', '0',
            '40', (string) $height,
            '1', $this->dxfSafeText($text),
        ];
    }

    private function dxfTextRight(float $x, float $y, string $text, string $layer, int $color, float $height = 2.0): array
    {
        return [
            '0', 'TEXT', '8', $layer, '62', (string) $color,
            '7', 'FTTH',
            '10', '0', '20', '0', '30', '0',
            '40', (string) $height,
            '72', '2',
            '11', (string) $x, '21', (string) $y, '31', '0',
            '1', $this->dxfSafeText($text),
        ];
    }

    private function dxfSafeText(string $text): string
    {
        $map = [
            'č' => 'c', 'Č' => 'C', 'ć' => 'c', 'Ć' => 'C',
            'š' => 's', 'Š' => 'S', 'ž' => 'z', 'Ž' => 'Z',
            'đ' => 'dj', 'Đ' => 'Dj', 'dž' => 'dz', 'Dž' => 'Dz', 'DŽ' => 'DZ',
        ];

        return str_replace(["\r", "\n"], ' ', strtr($text, $map));
    }
}
