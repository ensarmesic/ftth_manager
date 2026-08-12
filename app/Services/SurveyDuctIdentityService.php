<?php

namespace App\Services;

class SurveyDuctIdentityService
{
    public function __construct(private readonly GeometryService $geometry) {}

    /**
     * Return the duct identities recorded at one survey point.
     *
     * @return array<string, array{count:int,type:string,color:?string,tag:?string,transit:bool}>
     */
    public function identitiesAt(array $point): array
    {
        if (! empty($point['duct_identities'])) {
            return $this->explicitIdentities($point['duct_identities']);
        }

        if (! $point['microduct_type']) {
            return [];
        }

        if ($point['microduct_type'] === '14/10' && count($point['colors'] ?? []) > 0) {
            $identities = [];
            foreach ($point['colors'] as $color) {
                $count = max(1, (int) ($point['color_counts'][$color] ?? 1));
                $key = '14/10|'.$color.($point['zo_tag'] !== null ? '|zo:'.$point['zo_tag'] : '');
                $identities[$key] = $this->identity($count, '14/10', $color, $point['zo_tag'], $point['transit'] ?? false);
            }

            return $identities;
        }

        $tag = $point['zo_tag'];
        $key = $point['microduct_type'].'|'.($tag !== null ? 'zo:'.$tag : 'anon');

        return [$key => $this->identity(
            (int) $point['microduct_count'],
            $point['microduct_type'],
            $point['colors'][0] ?? null,
            $tag,
            $point['transit'] ?? false,
        )];
    }

    public function inferImplicitCabinetTags(array $ducts, float $nodeMergeMeters): array
    {
        foreach ($ducts as $index => $duct) {
            if ($duct['zo_tag'] !== null || ($duct['transit'] ?? false) === true || count($duct['path']) < 2) {
                continue;
            }

            $tags = [];
            foreach ($ducts as $candidateIndex => $candidate) {
                if ($candidateIndex === $index || $candidate['zo_tag'] === null) {
                    continue;
                }
                foreach ($this->pathEndpoints($duct['path']) as $endpoint) {
                    if ($this->geometry->distanceToRoute($endpoint[0], $endpoint[1], $candidate['path']) <= $nodeMergeMeters) {
                        $tags[$candidate['zo_tag']] = true;
                    }
                }
            }

            if (count($tags) !== 1) {
                continue;
            }

            $tag = (string) array_key_first($tags);
            $ducts[$index]['zo_tag'] = $tag;
            $ducts[$index]['key'] = $duct['microduct_type'].'|zo:'.$tag;
            $ducts[$index]['label'] = $this->label([
                'type' => $duct['microduct_type'],
                'color' => $duct['color'],
                'tag' => $tag,
            ], $duct['microduct_count']);
        }

        return $ducts;
    }

    public function label(array $attributes, int $count): string
    {
        $countLabel = $count > 1 ? $count.'x' : '';
        $suffix = $attributes['color'] ?? ($attributes['tag'] !== null ? 'ZO '.$attributes['tag'] : '');

        return trim('MC '.$countLabel.$attributes['type'].' '.$suffix);
    }

    private function explicitIdentities(array $recordedIdentities): array
    {
        $identities = [];
        foreach ($recordedIdentities as $identity) {
            $colors = $identity['type'] === '14/10' ? $identity['colors'] : [];
            if (count($colors) > 0) {
                foreach ($colors as $color) {
                    $key = $identity['type'].'|'.$color.($identity['tag'] !== null ? '|zo:'.$identity['tag'] : '');
                    $identities[$key] = $this->identity(
                        $identity['count'],
                        $identity['type'],
                        $color,
                        $identity['tag'],
                        $identity['transit'] ?? false,
                    );
                }

                continue;
            }

            $tag = $identity['tag'];
            $key = $identity['type'].'|'.($tag !== null ? 'zo:'.$tag : 'anon');
            $identities[$key] = $this->identity(
                $identity['count'],
                $identity['type'],
                null,
                $tag,
                $identity['transit'] ?? false,
            );
        }

        return $identities;
    }

    private function identity(int $count, string $type, ?string $color, ?string $tag, bool $transit): array
    {
        return compact('count', 'type', 'color', 'tag', 'transit');
    }

    private function pathEndpoints(array $path): array
    {
        return [$path[0], $path[count($path) - 1]];
    }
}
