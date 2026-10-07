<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a planned-session edit's shape: move (date — a swap with the rest
 * day it lands on) or skip (excuse the day before it passes — see
 * {@see \App\Models\PlannedSession::$skipped}), never both, plus an optional
 * explicit pin/unpin toggle that a move never carries. Which days may take each edit is
 * {@see \App\Services\Run\Plan\SessionEditRules}, checked under the plan's
 * lock in {@see \App\Http\Controllers\PlanController::update()}. Any field
 * left out keeps its current stored value. Per-segment
 * editing (a Tempo day's warmup length, an Interval day's rep count) isn't a
 * request field here — segments are computed fresh at render time by
 * {@see \App\Services\Run\Plan\SegmentGenerator}, not stored.
 */
class UpdatePlannedSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'date' => ['sometimes', 'date', 'prohibits:skipped,pinned'],
            'skipped' => ['sometimes', 'boolean'],
            'pinned' => ['sometimes', 'boolean'],
        ];
    }
}
