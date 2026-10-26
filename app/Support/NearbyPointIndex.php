<?php

namespace App\Support;

/**
 * Grid index for "is there already a point within N metres?" checks on
 * projected (metric) coordinates. Points are grouped (e.g. by source CRS)
 * so coordinates from different zones never match each other.
 */
final class NearbyPointIndex
{
    private array $cells = [];

    public function __construct(private readonly float $radius)
    {
        if ($radius <= 0) {
            throw new \InvalidArgumentException('Radius must be positive.');
        }
    }

    /** Payload of the first stored point within the radius, or null. */
    public function find(float $x, float $y, string $group = ''): mixed
    {
        [$cellX, $cellY] = $this->cell($x, $y);
        for ($gx = $cellX - 1; $gx <= $cellX + 1; $gx++) {
            for ($gy = $cellY - 1; $gy <= $cellY + 1; $gy++) {
                foreach ($this->cells[$group][$gx][$gy] ?? [] as [$px, $py, $payload]) {
                    if (hypot($x - $px, $y - $py) <= $this->radius) {
                        return $payload;
                    }
                }
            }
        }

        return null;
    }

    public function add(float $x, float $y, mixed $payload = true, string $group = ''): void
    {
        [$cellX, $cellY] = $this->cell($x, $y);
        $this->cells[$group][$cellX][$cellY][] = [$x, $y, $payload];
    }

    /** Adds the point unless one is already within the radius; true when added. */
    public function addIfFree(float $x, float $y, string $group = ''): bool
    {
        if ($this->find($x, $y, $group) !== null) {
            return false;
        }
        $this->add($x, $y, true, $group);

        return true;
    }

    private function cell(float $x, float $y): array
    {
        return [(int) floor($x / $this->radius), (int) floor($y / $this->radius)];
    }
}
