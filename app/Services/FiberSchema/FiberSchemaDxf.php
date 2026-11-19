<?php

namespace App\Services\FiberSchema;

/**
 * Writes a FiberSchemaDrawing to DXF R12 (AC1009), the version every AutoCAD and CAD viewer opens.
 *
 * - drawing units are millimetres on paper, the sheet's lower-left corner is the origin
 * - every entity uses its layer's colour and linetype (ByLayer); only fiber colour squares
 *   carry their own colour index, because that colour is the information
 * - ODO cabinets are inserts of the block FO_ODO with attributes NAZIV, SPLITTER, VLAKNA and
 *   KUCE, so they can be counted, selected and extracted to a table in AutoCAD
 * - text is stored in code page ANSI_1250, so č, ć, š, ž and đ stay correct
 */
class FiberSchemaDxf
{
    private const ODO_BLOCK_WIDTH = 26.0;

    private const STYLE = 'FO_TEKST';

    private const STYLE_BOLD = 'FO_TEKST_B';

    private float $sheetHeight;

    /** @var list<string> */
    private array $out = [];

    public function render(array $drawing): string
    {
        $this->out = [];
        $sheet = $drawing['sheet'];
        $this->sheetHeight = $sheet['height'];

        $this->header($sheet);
        $this->tables($sheet);
        $this->blocks();
        $this->pair(0, 'SECTION');
        $this->pair(2, 'ENTITIES');
        foreach ($drawing['items'] as $item) {
            match ($item['t']) {
                'line' => $this->line($item['x1'], $item['y1'], $item['x2'], $item['y2'], $item['layer']),
                'rect' => $this->rect($item['x'], $item['y'], $item['w'], $item['h'], $item['layer']),
                'circle' => $this->circle($item),
                'text' => $this->text($item['x'], $item['y'], $item['s'], $item['h'], $item['layer'], $item['ha'], $item['va'], $item['bold']),
                'swatch' => $this->swatch($item),
                'odo' => $this->odo($item),
                default => null,
            };
        }
        $this->pair(0, 'ENDSEC');
        $this->pair(0, 'EOF');

        return implode("\r\n", $this->out)."\r\n";
    }

    // ── Sections ─────────────────────────────────────────────────────────────

    private function header(array $sheet): void
    {
        $this->pair(0, 'SECTION');
        $this->pair(2, 'HEADER');
        $this->pair(9, '$ACADVER');
        $this->pair(1, 'AC1009');
        $this->pair(9, '$DWGCODEPAGE');
        $this->pair(3, 'ANSI_1250');
        $this->pair(9, '$INSBASE');
        $this->point(0, 0, 0);
        $this->pair(9, '$EXTMIN');
        $this->point(0, 0, 0);
        $this->pair(9, '$EXTMAX');
        $this->point($sheet['width'], $sheet['height'], 0);
        $this->pair(9, '$LIMMIN');
        $this->pair(10, '0.0');
        $this->pair(20, '0.0');
        $this->pair(9, '$LIMMAX');
        $this->pair(10, $this->num($sheet['width']));
        $this->pair(20, $this->num($sheet['height']));
        $this->pair(9, '$LTSCALE');
        $this->pair(40, '1.0');
        $this->pair(9, '$TEXTSTYLE');
        $this->pair(7, self::STYLE);
        $this->pair(9, '$CLAYER');
        $this->pair(8, '0');
        $this->pair(9, '$ATTMODE');
        $this->pair(70, '1');
        $this->pair(0, 'ENDSEC');
    }

    private function tables(array $sheet): void
    {
        $this->pair(0, 'SECTION');
        $this->pair(2, 'TABLES');

        // Opens zoomed to the whole sheet.
        $this->pair(0, 'TABLE');
        $this->pair(2, 'VPORT');
        $this->pair(70, '1');
        $this->pair(0, 'VPORT');
        $this->pair(2, '*ACTIVE');
        $this->pair(70, '0');
        $this->pair(10, '0.0');
        $this->pair(20, '0.0');
        $this->pair(11, '1.0');
        $this->pair(21, '1.0');
        $this->pair(12, $this->num($sheet['width'] / 2));
        $this->pair(22, $this->num($sheet['height'] / 2));
        $this->pair(13, '0.0');
        $this->pair(23, '0.0');
        $this->pair(14, '1.0');
        $this->pair(24, '1.0');
        $this->pair(15, '10.0');
        $this->pair(25, '10.0');
        $this->pair(16, '0.0');
        $this->pair(26, '0.0');
        $this->pair(36, '1.0');
        $this->pair(17, '0.0');
        $this->pair(27, '0.0');
        $this->pair(37, '0.0');
        $this->pair(40, $this->num($sheet['height'] * 1.05));
        $this->pair(41, $this->num(max(1.0, $sheet['width'] / $sheet['height'])));
        $this->pair(42, '50.0');
        $this->pair(43, '0.0');
        $this->pair(44, '0.0');
        $this->pair(50, '0.0');
        $this->pair(51, '0.0');
        $this->pair(71, '0');
        $this->pair(72, '100');
        $this->pair(73, '1');
        $this->pair(74, '3');
        $this->pair(75, '0');
        $this->pair(76, '0');
        $this->pair(77, '0');
        $this->pair(78, '0');
        $this->pair(0, 'ENDTAB');

        $this->pair(0, 'TABLE');
        $this->pair(2, 'LTYPE');
        $this->pair(70, '2');
        $this->pair(0, 'LTYPE');
        $this->pair(2, 'CONTINUOUS');
        $this->pair(70, '0');
        $this->pair(3, 'Solid line');
        $this->pair(72, '65');
        $this->pair(73, '0');
        $this->pair(40, '0.0');
        $this->pair(0, 'LTYPE');
        $this->pair(2, 'DASHED');
        $this->pair(70, '0');
        $this->pair(3, 'Dashed __ __ __');
        $this->pair(72, '65');
        $this->pair(73, '2');
        $this->pair(40, '6.0');
        $this->pair(49, '4.0');
        $this->pair(49, '-2.0');
        $this->pair(0, 'ENDTAB');

        $layers = ['0' => [7, 'CONTINUOUS']] + array_map(fn (array $layer) => [$layer[0], $layer[2]], FiberSchemaDrawing::LAYERS);
        $this->pair(0, 'TABLE');
        $this->pair(2, 'LAYER');
        $this->pair(70, (string) count($layers));
        foreach ($layers as $name => [$color, $linetype]) {
            $this->pair(0, 'LAYER');
            $this->pair(2, (string) $name);
            $this->pair(70, '0');
            $this->pair(62, (string) $color);
            $this->pair(6, $linetype);
        }
        $this->pair(0, 'ENDTAB');

        $this->pair(0, 'TABLE');
        $this->pair(2, 'STYLE');
        $this->pair(70, '3');
        foreach (['STANDARD' => 'txt', self::STYLE => 'arial.ttf', self::STYLE_BOLD => 'arialbd.ttf'] as $name => $font) {
            $this->pair(0, 'STYLE');
            $this->pair(2, $name);
            $this->pair(70, '0');
            $this->pair(40, '0.0');
            $this->pair(41, '1.0');
            $this->pair(50, '0.0');
            $this->pair(71, '0');
            $this->pair(42, '2.5');
            $this->pair(3, $font);
            $this->pair(4, '');
        }
        $this->pair(0, 'ENDTAB');
        $this->pair(0, 'ENDSEC');
    }

    /** FO_ODO: a 26 × 12 mm cabinet box centred on its insertion point, with attributes. */
    private function blocks(): void
    {
        $w = self::ODO_BLOCK_WIDTH / 2;
        $h = 6.0;
        $this->pair(0, 'SECTION');
        $this->pair(2, 'BLOCKS');
        $this->pair(0, 'BLOCK');
        $this->pair(8, '0');
        $this->pair(2, 'FO_ODO');
        $this->pair(70, '2');
        $this->point(0, 0, 0);
        $this->pair(3, 'FO_ODO');
        foreach ([[-$w, -$h, $w, -$h], [$w, -$h, $w, $h], [$w, $h, -$w, $h], [-$w, $h, -$w, -$h]] as [$x1, $y1, $x2, $y2]) {
            $this->pair(0, 'LINE');
            $this->pair(8, '0');
            $this->pair(62, '0');
            $this->point($x1, $y1, 0);
            $this->pair(11, $this->num($x2));
            $this->pair(21, $this->num($y2));
            $this->pair(31, '0.0');
        }
        foreach ([['NAZIV', 'Naziv ODO', 1.6, 3.5, 0], ['SPLITTER', 'Splitteri', -3.0, 2.5, 0], ['VLAKNA', 'Vlakna', -8.0, 2.5, 1], ['KUCE', 'Kuce / kapacitet', -11.0, 2.5, 1]] as [$tag, $prompt, $y, $height, $flags]) {
            $this->pair(0, 'ATTDEF');
            $this->pair(8, '0');
            $this->point(0, $y, 0);
            $this->pair(40, $this->num($height));
            $this->pair(1, '');
            $this->pair(3, $prompt);
            $this->pair(2, $tag);
            $this->pair(70, (string) $flags);
            $this->pair(7, self::STYLE);
            $this->pair(72, '1');
            $this->pair(11, '0.0');
            $this->pair(21, $this->num($y));
            $this->pair(31, '0.0');
            $this->pair(74, '2');
        }
        $this->pair(0, 'ENDBLK');
        $this->pair(8, '0');
        $this->pair(0, 'ENDSEC');
    }

    // ── Entities ─────────────────────────────────────────────────────────────

    private function line(float $x1, float $y1, float $x2, float $y2, string $layer, ?int $color = null): void
    {
        $this->pair(0, 'LINE');
        $this->pair(8, $layer);
        if ($color !== null) {
            $this->pair(62, (string) $color);
        }
        $this->point($x1, $this->y($y1), 0);
        $this->pair(11, $this->num($x2));
        $this->pair(21, $this->num($this->y($y2)));
        $this->pair(31, '0.0');
    }

    private function rect(float $x, float $y, float $w, float $h, string $layer): void
    {
        $this->line($x, $y, $x + $w, $y, $layer);
        $this->line($x + $w, $y, $x + $w, $y + $h, $layer);
        $this->line($x + $w, $y + $h, $x, $y + $h, $layer);
        $this->line($x, $y + $h, $x, $y, $layer);
    }

    private function circle(array $item): void
    {
        $this->pair(0, 'CIRCLE');
        $this->pair(8, $item['layer']);
        $this->point($item['x'], $this->y($item['y']), 0);
        $this->pair(40, $this->num($item['r']));
    }

    private function text(float $x, float $y, string $text, float $height, string $layer, string $ha = 'l', string $va = 'b', bool $bold = false): void
    {
        $halign = ['l' => 0, 'c' => 1, 'r' => 2][$ha] ?? 0;
        $valign = ['b' => 0, 'm' => 2, 't' => 3][$va] ?? 0;
        $this->pair(0, 'TEXT');
        $this->pair(8, $layer);
        $this->point($x, $this->y($y), 0);
        $this->pair(40, $this->num($height));
        $this->pair(1, $this->encode($text));
        $this->pair(7, $bold ? self::STYLE_BOLD : self::STYLE);
        if ($halign || $valign) {
            $this->pair(72, (string) $halign);
            $this->pair(11, $this->num($x));
            $this->pair(21, $this->num($this->y($y)));
            $this->pair(31, '0.0');
            $this->pair(73, (string) $valign);
        }
    }

    /** Filled colour square (SOLID) with an outline; traced fibers get a diagonal. */
    private function swatch(array $item): void
    {
        $x = $item['x'];
        $y = $item['y'];
        $s = $item['size'];
        $this->pair(0, 'SOLID');
        $this->pair(8, $item['layer']);
        $this->pair(62, (string) $item['aci']);
        // SOLID corner order: 1, 2, then 4 and 3 (zig-zag).
        $this->point($x, $this->y($y), 0);
        $this->pair(11, $this->num($x + $s));
        $this->pair(21, $this->num($this->y($y)));
        $this->pair(31, '0.0');
        $this->pair(12, $this->num($x));
        $this->pair(22, $this->num($this->y($y + $s)));
        $this->pair(32, '0.0');
        $this->pair(13, $this->num($x + $s));
        $this->pair(23, $this->num($this->y($y + $s)));
        $this->pair(33, '0.0');
        $this->rect($x, $y, $s, $s, $item['layer']);
        if ($item['traced']) {
            $this->line($x, $y + $s, $x + $s, $y, $item['layer']);
        }
    }

    private function odo(array $item): void
    {
        $layer = $item['layer'];
        $x = $item['x'];
        $y = $this->y($item['y']);
        $this->pair(0, 'INSERT');
        $this->pair(8, $layer);
        $this->pair(66, '1');
        $this->pair(2, 'FO_ODO');
        $this->point($x, $y, 0);
        $this->pair(41, $this->num($item['w'] / self::ODO_BLOCK_WIDTH));
        $this->pair(42, '1.0');
        $this->pair(43, '1.0');
        foreach ([['NAZIV', $item['name'], 1.6, 3.5, 0, true], ['SPLITTER', $item['splitters'], -3.0, 2.5, 0, false], ['VLAKNA', $item['fibers'], -8.0, 2.5, 1, false], ['KUCE', $item['houses'], -11.0, 2.5, 1, false]] as [$tag, $value, $dy, $height, $flags, $bold]) {
            $this->pair(0, 'ATTRIB');
            $this->pair(8, $layer);
            $this->point($x, $y + $dy, 0);
            $this->pair(40, $this->num($height));
            $this->pair(1, $this->encode((string) $value));
            $this->pair(2, $tag);
            $this->pair(70, (string) $flags);
            $this->pair(7, $bold ? self::STYLE_BOLD : self::STYLE);
            $this->pair(72, '1');
            $this->pair(11, $this->num($x));
            $this->pair(21, $this->num($y + $dy));
            $this->pair(31, '0.0');
            $this->pair(74, '2');
        }
        $this->pair(0, 'SEQEND');
        $this->pair(8, $layer);
    }

    // ── Low level ────────────────────────────────────────────────────────────

    private function y(float $y): float
    {
        return $this->sheetHeight - $y;
    }

    private function point(float $x, float $y, float $z): void
    {
        $this->pair(10, $this->num($x));
        $this->pair(20, $this->num($y));
        $this->pair(30, $this->num($z));
    }

    private function pair(int $code, string $value): void
    {
        $this->out[] = (string) $code;
        $this->out[] = $value;
    }

    private function num(float $value): string
    {
        $formatted = rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');

        return str_contains($formatted, '.') ? $formatted : $formatted.'.0';
    }

    /** DXF R12 text in the drawing's code page; control characters and %% codes are neutralised. */
    private function encode(string $text): string
    {
        $text = str_replace(["\r", "\n", "\t"], ' ', $text);
        $text = str_replace('%%', '%', $text);
        $converted = @iconv('UTF-8', 'CP1250//TRANSLIT', $text);

        return $converted === false ? (string) preg_replace('/[^\x20-\x7E]/', '?', $text) : $converted;
    }
}
