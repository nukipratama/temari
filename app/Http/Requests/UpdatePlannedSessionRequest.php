<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\DistanceBand;
use App\Enums\PaceBand;
use App\Enums\SessionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a planned-session edit: move (date), resize (distance_band),
 * block (session_type = rest), and/or an explicit pin/unpin toggle. Any
 * field left out keeps its current stored value ({@see \App\Http\Controllers\PlanController::update()}).
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
            'distance_band' => ['sometimes', Rule::enum(DistanceBand::class)],
            'pace_band' => ['sometimes', 'nullable', Rule::enum(PaceBand::class)],
            'pinned' => ['sometimes', 'boolean'],
        ];
    }
}
