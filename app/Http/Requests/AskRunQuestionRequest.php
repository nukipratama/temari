<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\AI\RunQuestion;
use App\Models\Activity;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the free text on the ask-about-this-run endpoint. Ownership is
 * checked here rather than in the controller because a typed FormRequest is
 * resolved before the action body runs, so a controller-side check would let a
 * foreign activity id answer with a validation redirect instead of a 403.
 */
class AskRunQuestionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && Activity::query()
            ->whereKey((int) $this->route('activity'))
            ->where('user_id', $user->id)
            ->exists();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'question' => ['required', 'string', 'min:3', 'max:'.RunQuestion::MAX_QUESTION_LENGTH],
        ];
    }

    public function question(): string
    {
        return trim((string) $this->validated('question'));
    }
}
