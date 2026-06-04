<?php

declare(strict_types=1);

namespace App\Livewire\Pulse\Concerns;

trait SumsPulseTotals
{
    /**
     * Pulse's aggregateTotal() is float|Collection (Collection only for
     * multi-aggregate calls); a single-aggregate total is always a scalar, so
     * coerce it to int safely. Pass the aggregateTotal() result in.
     */
    protected function asCount(mixed $total): int
    {
        return is_numeric($total) ? (int) $total : 0;
    }
}
