<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\AI\RunQuestion;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the free text on the ask-about-this-run endpoint. Ownership of the
 * activity is enforced in the controller, which needs the resolved user.
 */
class AskRunQuestionRequest extends FormRequest
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
            'question' => ['required', 'string', 'min:3', 'max:'.RunQuestion::MAX_QUESTION_LENGTH],
        ];
    }

    public function question(): string
    {
        return trim((string) $this->validated('question'));
    }
}
