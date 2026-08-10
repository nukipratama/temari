<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Backed enum for a planned session's row-level lifecycle. Every generated
 * row starts `Planned`; this is never mutated by regeneration or the
 * readiness clamp (both are render/generation-time concerns that leave the
 * stored row alone). {@see \App\Http\Controllers\PlanController} derives
 * `Done`/`Missed` at render time for past dates by checking whether the user
 * logged an activity that day, without writing it back.
 */
enum PlannedSessionStatus: string
{
    case Planned = 'planned';
    case Done = 'done';
    case Missed = 'missed';
}
