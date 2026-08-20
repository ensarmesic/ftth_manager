<?php

namespace App\Services;

class SurveyPointClassifier
{
    private const COLOR_WORDS = [
        'zelen' => 'Zelena', 'crven' => 'Crvena', 'plav' => 'Plava', 'zut' => 'Zuta',
        'bjel' => 'Bjela', 'bijel' => 'Bjela', 'narandz' => 'Narandzasta', 'ljubicast' => 'Ljubicasta', 'siv' => 'Siva',
    ];

    private const COLOR_ABBREVIATIONS = [
        'ze' => 'Zelena', 'cr' => 'Crvena', 'pl' => 'Plava', 'zu' => 'Zuta', 'bj' => 'Bjela',
    ];

    public function __construct(
        private readonly SurveyPointCodeNormalizer $codeNormalizer,
        private readonly SurveyTargetZoParser $targetZoParser,
    ) {}

    public function classify(string $code): array
    {
        $n = mb_strtolower(trim($code));
        $n = strtr($n, ['š' => 's', 'ž' => 'z', 'č' => 'c', 'ć' => 'c', 'đ' => 'dj']);

        $microductType = null;
        if (preg_match('/14\s*\/?\s*(10|12)|(?<!\d)14\s*mc|\bfi\s*14\b/', $n)) {
            $microductType = '14/10';
        } elseif (preg_match('/10\s*\/\s*[78]|10\/\/8|\dx10|mc\s*10\b|mc\.\s*10/', $n)) {
            $microductType = '10/8';
        }

        // "MD" (the shared reserve/casing duct bundled alongside the coloured ones) is its
        // own physical duct that keeps running after the colours have all split away — it's
        // tracked the same way a colour is (see below) so it reads as one continuous line
        // instead of vanishing once the point stops restating a colour. In this file it's
        // always 14/10 even on later points that no longer spell that out.
        $hasReserveDuct = (bool) preg_match('/\bmd\b/', $n);
        if ($microductType === null && $hasReserveDuct) {
            $microductType = '14/10';
        }

        $microductCount = 1;
        if (preg_match('/\b(\d{1,2})\s*x?\s*fi\s*14\b/', $n, $m)) {
            $microductCount = max(1, (int) $m[1]);
        } elseif (preg_match('/(?:^|[^\d])x\s*(\d{1,2})(?:\.\d)?\b/', $n, $m)) {
            $microductCount = max(1, (int) $m[1]);
        } elseif (preg_match('/(?:^|[^\d])(\d{1,2})\s*x\s*1[04]/', $n, $m)) {
            $microductCount = max(1, (int) $m[1]);
        }

        // Duct colours: full words ("Zelena i Plava") and abbreviations ("Ze+Pl+Cr").
        $colors = [];
        foreach (self::COLOR_WORDS as $stem => $color) {
            if (preg_match('/'.$stem.'[a-z]*/', $n)) {
                $colors[$color] = $color;
            }
        }
        if (preg_match_all('/[+\-]\s*(ze|cr|pl|zu|bj)\b/', $n, $abbr)) {
            foreach ($abbr[1] as $a) {
                $color = self::COLOR_ABBREVIATIONS[$a];
                $colors[$color] = $color;
            }
        }
        if ($hasReserveDuct) {
            $colors['MD'] = 'MD';
        }
        $colors = array_values($colors);
        $colorCounts = $this->parseColorCounts($n, $colors, $microductCount);
        if ($microductType === '14/10' && $colorCounts !== []) {
            $microductCount = max($microductCount, array_sum($colorCounts));
        }

        // Explicit ZO destinations are parsed independently from geometry. Keep the
        // legacy short field notation (Z1/Z0-1) only as a backwards-compatible tag;
        // target_zo_explicit remains false for those non-ZO descriptions.
        $targetZo = $this->targetZoParser->parse($code);
        $zoTag = $targetZo['target_zo'] !== null
            ? substr($targetZo['target_zo'], 3)
            : null;
        if ($zoTag === null && preg_match_all('/z(?:\s*[o0](?:rmar)?)?[\s\-_.]*([0-9]+(?:[.\-][0-9]+)*)/', $n, $m) && count($m[1]) > 0) {
            $zoTag = $this->codeNormalizer->cabinetTag(end($m[1]));
        } elseif (preg_match('/zelen[ai]\s+ormar(?:ic[ai])?\s*(?:br\.?\s*)?([0-9]+(?:[.\-][0-9]+)*)/', $n, $m)) {
            // Common AutoCAD callout: "ZELENA ORMARICA BR. 7". It denotes the
            // same destination as ZO-7 and must be usable as the end of a tagged
            // 10/8 customer route reconstructed through the shared trench.
            $zoTag = $this->codeNormalizer->cabinetTag($m[1]);
        }

        $isHousePoint = $this->codeNormalizer->isHousePoint($n);
        $hasSling = (bool) preg_match('/\bslinga?\b/', $n);
        $hasCustomerDuct = (bool) preg_match('/10\s*\/\s*[78]|10\/\/8|mc\s*10\b|mc\.\s*10/', $n);
        $isPreparedSling = $hasSling && ($isHousePoint || $hasCustomerDuct);
        $houseRef = null;
        if ($isHousePoint && preg_match('/(?:za|do)\s+kuc[a-z]*\s*[:#-]?\s*([a-z0-9][a-z0-9._\/-]*)/u', $n, $m)) {
            $houseRef = mb_strtoupper($m[1]);
        }

        $kind = match (true) {
            $n === '' => 'other',
            $isPreparedSling || $isHousePoint => 'sling',
            (bool) preg_match('/\brov\b|\brob\b|rov\+|^mikrodukt/', $n) => 'trench',
            (bool) preg_match('/spojnic/', $n) => 'splice',
            // A bare "sling/slinga/izvod" with no house word is a cable RESERVE loop
            // (extra coiled length for a future splice), not a customer connection —
            // 'sling' is reserved for points that actually name a house (see above).
            $hasSling || (bool) preg_match('/izvod|sluga\b/', $n) => 'loop',
            (bool) preg_match('/\bsaht\b/', $n) => 'manhole',
            (bool) preg_match('/busenje/', $n) => 'boring',
            (bool) preg_match('/\bstub\b/', $n) => 'pole',
            (bool) preg_match('/odf/', $n) => 'odf',
            // Field crews also mark green cabinets with a bare code such as Z1 or Z 1.1.
            // Anchor the short form to the whole description so a route tag such as
            // "Rov + mc 10/8 -Z1" remains a trench destination, not a cabinet point.
            (bool) preg_match('/zelen[ai]\s+ormar(?:ic[ai])?|^z\s+ormar|^z\s*[o0](?![a-z])|^z\s*\d+(?:[.\-]\d+)*\s*$/', $n) => 'cabinet',
            default => 'other',
        };

        return [
            'kind' => $kind,
            'microduct_type' => $microductType,
            'microduct_count' => $microductCount,
            'colors' => $colors,
            'color_counts' => $colorCounts,
            'zo_tag' => $zoTag,
            'raw_description' => $targetZo['raw_description'],
            'target_zo' => $targetZo['target_zo'],
            'target_zo_match' => $targetZo['matched_text'],
            'target_zo_explicit' => $targetZo['explicit'],
            'duct_identities' => $this->parseMultipleDuctIdentities($n),
            'prepared_sling' => $isPreparedSling,
            'house_ref' => $houseRef,
            'transit' => (bool) preg_match('/\btranzit\b/', $n),
        ];
    }

    /**
     * Read per-colour quantities from field notation such as
     * "5x fi 14 2x Zelena, 2x Plava i Zuta". The total belongs to the bundle;
     * it must not be copied onto every colour (which would incorrectly create 15 MC).
     *
     * @param  array<int,string>  $colors
     * @return array<string,int>
     */
    private function parseColorCounts(string $description, array $colors, int $total): array
    {
        if ($colors === []) {
            return [];
        }

        $counts = [];
        foreach (self::COLOR_WORDS as $stem => $color) {
            if (preg_match('/\b(\d{1,2})\s*x\s*'.$stem.'[a-z]*/', $description, $match)
                || preg_match('/'.$stem.'[a-z]*\s*x\s*(\d{1,2})\b/', $description, $match)) {
                $counts[$color] = max(1, (int) $match[1]);
            }
        }

        $unassigned = array_values(array_diff($colors, array_keys($counts)));
        $remaining = $total - array_sum($counts);
        if (count($unassigned) === 1 && $remaining > 0) {
            $counts[$unassigned[0]] = $remaining;
        } else {
            foreach ($unassigned as $color) {
                $counts[$color] = 1;
            }
        }

        return $counts;
    }

    /**
     * A semicolon or pipe separates physical ducts recorded at the same point:
     * "Rov; 14/10 Zelena; 14/10 Plava; 10/8 X1 ZO 3".
     */
    private function parseMultipleDuctIdentities(string $description): array
    {
        $parts = preg_split('/\s*[;|]\s*/', $description) ?: [];
        if (count($parts) < 2) {
            $hasFourteen = (bool) preg_match('/14\s*\/?\s*(10|12)|(?<!\d)14\s*mc|\bfi\s*14\b/', $description);
            $hasTen = (bool) preg_match('/10\s*\/\s*[78]|10\/\/8|mc\s*10\b|mc\.\s*10/', $description);
            if ($hasFourteen && $hasTen) {
                $colors = [];
                foreach (self::COLOR_WORDS as $stem => $color) {
                    if (preg_match('/'.$stem.'[a-z]*/', $description)) {
                        $colors[$color] = $color;
                    }
                }
                $tag = null;
                if (preg_match_all('/z(?:\s*[o0](?:rmar)?)?[\s\-_.]*([0-9]+(?:[.\-][0-9]+)*)/', $description, $matches)) {
                    $tag = $this->codeNormalizer->cabinetTag(end($matches[1]));
                }

                return [
                    ['type' => '14/10', 'count' => 1, 'colors' => array_values($colors), 'tag' => null],
                    ['type' => '10/8', 'count' => 1, 'colors' => [], 'tag' => $tag],
                ];
            }

            return [];
        }

        $identities = [];
        foreach ($parts as $part) {
            $type = match (true) {
                (bool) preg_match('/14\s*\/?\s*(10|12)|(?<!\d)14\s*mc|\bfi\s*14\b/', $part) => '14/10',
                (bool) preg_match('/10\s*\/\s*[78]|10\/\/8|\dx10|mc\s*10\b|mc\.\s*10/', $part) => '10/8',
                default => null,
            };
            if ($type === null) {
                continue;
            }

            $count = 1;
            if (preg_match('/\b(\d{1,2})\s*x?\s*fi\s*14\b/', $part, $m)
                || preg_match('/(?:^|[^\d])x\s*(\d{1,2})(?:\.\d)?\b/', $part, $m)
                || preg_match('/(?:^|[^\d])(\d{1,2})\s*x\s*1[04]/', $part, $m)) {
                $count = max(1, (int) $m[1]);
            }

            $colors = [];
            foreach (self::COLOR_WORDS as $stem => $color) {
                if (preg_match('/'.$stem.'[a-z]*/', $part)) {
                    $colors[$color] = $color;
                }
            }
            if (preg_match_all('/[+\-]\s*(ze|cr|pl|zu|bj)\b/', $part, $matches)) {
                foreach ($matches[1] as $abbreviation) {
                    $colors[self::COLOR_ABBREVIATIONS[$abbreviation]] = self::COLOR_ABBREVIATIONS[$abbreviation];
                }
            }

            $tag = null;
            if (preg_match_all('/z(?:\s*[o0](?:rmar)?)?[\s\-_.]*([0-9]+(?:[.\-][0-9]+)*)/', $part, $matches) && count($matches[1]) > 0) {
                $tag = $this->codeNormalizer->cabinetTag(end($matches[1]));
            }

            $identities[] = [
                'type' => $type,
                'count' => $count,
                'colors' => array_values($colors),
                'tag' => $tag,
                'transit' => (bool) preg_match('/\btranzit\b/', $part),
            ];
        }

        return count($identities) > 1 ? $identities : [];
    }
}
