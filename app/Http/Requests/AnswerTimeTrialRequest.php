<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\PlannedSession;
use Illuminate\Foundation\Http\FormRequest;

class AnswerTimeTrialRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null
            && PlannedSession::query()->where('user_id', $user->id)->whereKey($this->route('plannedSession'))->exists();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'all_out' => ['required', 'boolean'],
        ];
    }
}
