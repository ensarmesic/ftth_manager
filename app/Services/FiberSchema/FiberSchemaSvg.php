<?php

namespace App\Services\FiberSchema;

/**
 * Renders a FiberSchemaDrawing to SVG (browser view and PDF), in the same millimetre
 * coordinates as the DXF, so screen, PDF and AutoCAD show one and the same drawing.
 */
class FiberSchemaSvg
{
    /** CAD text height is the cap height; Arial's cap height is about 0.716 of the font size. */
    private const CAP_HEIGHT = 0.716;

    /**
     * @param  bool  $interactive  adds data attributes and hover titles for the browser
     * @param  bool  $forPdf  geometry only, scaled mm → pt (the PDF renderer reads viewBox units as points);
     *                        the PDF puts the text on top with labels(), in a font that has č, ć and đ
     */
    public function render(array $drawing, bool $interactive = true, bool $forPdf = false): string
    {
        $sheet = $drawing['sheet'];
        $w = $this->num($sheet['width']);
        $h = $this->num($sheet['height']);
        $parts = [];
        if ($forPdf) {
            $points = 72 / 25.4;
            $pw = $this->num($sheet['width'] * $points);
            $ph = $this->num($sheet['height'] * $points);
            $parts[] = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 '.$pw.' '.$ph.'" width="'.$pw.'" height="'.$ph.'" >';
            $parts[] = '<g transform="scale('.$this->num($points).')">';
        } else {
            $parts[] = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 '.$w.' '.$h.'" width="'.$w.'mm" height="'.$h.'mm" font-family="Arial, Helvetica, sans-serif" class="fiber-sheet" role="img" aria-label="Fiber šema">';
        }
        $parts[] = '<rect x="0" y="0" width="'.$w.'" height="'.$h.'" fill="#ffffff"/>';
        foreach ($drawing['items'] as $item) {
            $parts[] = match ($item['t']) {
                'line' => $this->line($item),
                'rect' => $this->rect($item),
                'circle' => $this->circle($item),
                'text' => $forPdf ? '' : $this->text($item['x'], $item['y'], $item['s'], $item['h'], $this->color($item['layer']), $item['ha'], $item['va'], $item['bold']),
                'swatch' => $this->swatch($item, $interactive),
                'odo' => $this->odo($item, $interactive, ! $forPdf),
                default => '',
            };
        }
        if ($forPdf) {
            $parts[] = '</g>';
        }
        $parts[] = '</svg>';

        return implode('', $parts);
    }

    private function line(array $item): string
    {
        $dash = (FiberSchemaDrawing::LAYERS[$item['layer']][2] ?? '') === 'DASHED' ? ' stroke-dasharray="4 2"' : '';

        return '<line x1="'.$this->num($item['x1']).'" y1="'.$this->num($item['y1']).'" x2="'.$this->num($item['x2']).'" y2="'.$this->num($item['y2']).'" stroke="'.$this->color($item['layer']).'" stroke-width="'.$this->weight($item['layer']).'"'.$dash.'/>';
    }

    private function rect(array $item): string
    {
        return '<rect x="'.$this->num($item['x']).'" y="'.$this->num($item['y']).'" width="'.$this->num($item['w']).'" height="'.$this->num($item['h']).'" fill="'.($item['fill'] ?? 'none').'" stroke="'.$this->color($item['layer']).'" stroke-width="'.$this->weight($item['layer']).'"/>';
    }

    private function circle(array $item): string
    {
        $color = $this->color($item['layer']);

        return '<circle cx="'.$this->num($item['x']).'" cy="'.$this->num($item['y']).'" r="'.$this->num($item['r']).'" fill="'.($item['fill'] ? $color : 'none').'" stroke="'.$color.'" stroke-width="0.25"/>';
    }

    private function text(float $x, float $y, string $text, float $height, string $color, string $ha, string $va, bool $bold): string
    {
        $anchor = ['l' => 'start', 'c' => 'middle', 'r' => 'end'][$ha] ?? 'start';
        // Baseline offsets instead of dominant-baseline, which PDF renderers ignore.
        $dy = match ($va) {
            'm' => $height / 2,
            't' => $height,
            default => 0.0,
        };

        return '<text x="'.$this->num($x).'" y="'.$this->num($y + $dy).'" font-size="'.$this->num($height / self::CAP_HEIGHT).'"'
            .($anchor !== 'start' ? ' text-anchor="'.$anchor.'"' : '').($bold ? ' font-weight="bold"' : '').' fill="'.$color.'">'.$this->escape($text).'</text>';
    }

    private function swatch(array $item, bool $interactive): string
    {
        $s = $this->num($item['size']);
        $x = $this->num($item['x']);
        $y = $this->num($item['y']);
        $svg = '<rect x="'.$x.'" y="'.$y.'" width="'.$s.'" height="'.$s.'" fill="'.$item['hex'].'" stroke="#334155" stroke-width="0.2">'
            .($interactive ? '<title>'.$this->escape($item['title']).'</title>' : '').'</rect>';
        if ($item['traced']) {
            $svg .= '<line x1="'.$x.'" y1="'.$this->num($item['y'] + $item['size']).'" x2="'.$this->num($item['x'] + $item['size']).'" y2="'.$y.'" stroke="#111827" stroke-width="0.45"/>';
        }

        return $svg;
    }

    /**
     * Every piece of text on the sheet, for renderers that place text themselves (PDF).
     *
     * @return list<array{x: float, y: float, s: string, h: float, ha: string, va: string, bold: bool, color: string}>
     */
    public function labels(array $drawing): array
    {
        $labels = [];
        foreach ($drawing['items'] as $item) {
            if ($item['t'] === 'text') {
                $labels[] = ['x' => $item['x'], 'y' => $item['y'], 's' => $item['s'], 'h' => $item['h'], 'ha' => $item['ha'], 'va' => $item['va'], 'bold' => $item['bold'], 'color' => $this->color($item['layer'])];
            } elseif ($item['t'] === 'odo') {
                $labels[] = ['x' => $item['x'], 'y' => $item['y'] - 1.6, 's' => $item['name'], 'h' => 3.5, 'ha' => 'c', 'va' => 'm', 'bold' => true, 'color' => '#0f172a'];
                $labels[] = ['x' => $item['x'], 'y' => $item['y'] + 3.0, 's' => $item['splitters'], 'h' => 2.5, 'ha' => 'c', 'va' => 'm', 'bold' => false, 'color' => '#334155'];
            }
        }

        return $labels;
    }

    private function odo(array $item, bool $interactive, bool $withText = true): string
    {
        $color = $this->color($item['layer']);
        $fill = $item['state'] === 'ok' ? '#f0fdf4' : '#fef2f2';
        $left = $item['x'] - $item['w'] / 2;
        $top = $item['y'] - $item['h'] / 2;
        $data = $interactive ? ' data-cabinet-id="'.$item['cabinet_id'].'" class="fiber-odo"' : '';

        return '<g'.$data.'>'
            .($interactive ? '<title>'.$this->escape($item['name'].' · '.$item['fibers'].' · '.$item['houses'].' kuća · '.$item['splitters']).'</title>' : '')
            .'<rect x="'.$this->num($left).'" y="'.$this->num($top).'" width="'.$this->num($item['w']).'" height="'.$this->num($item['h']).'" rx="0.8" fill="'.$fill.'" stroke="'.$color.'" stroke-width="0.5"/>'
            .($withText ? $this->text($item['x'], $item['y'] - 1.6, $item['name'], 3.5, '#0f172a', 'c', 'm', true)
                .$this->text($item['x'], $item['y'] + 3.0, $item['splitters'], 2.5, '#334155', 'c', 'm', false) : '')
            .'</g>';
    }

    private function color(string $layer): string
    {
        return FiberSchemaDrawing::LAYERS[$layer][1] ?? '#0f172a';
    }

    /** Line weights in mm, following ISO 128 (0.25 thin, 0.35 / 0.5 for main lines). */
    private function weight(string $layer): string
    {
        return match ($layer) {
            'FO-OKVIR', 'FO-ODF' => '0.5',
            'FO-KABEL', 'FO-KABEL-ODVOJAK', 'FO-KABEL-PRIMARNI', 'FO-UPOZORENJE', 'FO-ODO' => '0.45',
            'FO-ZAGLAVLJE' => '0.35',
            default => '0.25',
        };
    }

    private function num(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.') ?: '0';
    }

    private function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
