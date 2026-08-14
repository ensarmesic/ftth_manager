<?php

namespace App\Support;

class RequestPerformanceMetrics
{
    private int $queryCount = 0;

    private float $databaseDurationMs = 0.0;

    public function reset(): void
    {
        $this->queryCount = 0;
        $this->databaseDurationMs = 0.0;
    }

    public function recordQuery(float $durationMs): void
    {
        $this->queryCount++;
        $this->databaseDurationMs += $durationMs;
    }

    public function queryCount(): int
    {
        return $this->queryCount;
    }

    public function databaseDurationMs(): float
    {
        return $this->databaseDurationMs;
    }
}
