<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\SessionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a planned-session edit: move (date), block (session_type =
 * rest), skip (excuse the day before it passes — see
 * {@see \App\Models\PlannedSession::$skipped}), and/or an explicit
 * pin/unpin toggle. Any field left out keeps its current stored value
 * ({@see \App\Http\Controllers\PlanController::update()}). Per-segment
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
            'date' => ['sometimes', 'date'],
            'session_type' => ['sometimes', Rule::enum(SessionType::class)],
            'skipped' => ['sometimes', 'boolean'],
            'pinned' => ['sometimes', 'boolean'],
        ];
    }
}
