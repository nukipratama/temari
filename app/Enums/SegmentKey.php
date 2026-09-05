<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * A planned session's structural role within its own day — never stored
 * (see {@see \App\Services\Run\Plan\SegmentGenerator}, which computes a
 * day's full segment list fresh at render time). `Interval` is the hard rep
 * itself; `Recovery` is the easy gap between reps, distinct from `Main`
 * (a single continuous effort block on Easy/Long/Tempo days).
 */
enum SegmentKey: string
{
    case Warmup = 'warmup';
    case Main = 'main';
    case Interval = 'interval';
    case Recovery = 'recovery';
    case Cooldown = 'cooldown';
}
