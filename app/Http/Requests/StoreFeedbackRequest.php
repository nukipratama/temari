<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\FeedbackSubject;
use App\Models\Feedback;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a "this is wrong" flag. Ownership is checked here rather than in
 * the controller so a foreign subject id 403s instead of getting a validation
 * redirect; an unrecognised subject type falls through to the rules below so it
 * still reads as a 422 rather than a permission problem.
 */
class StoreFeedbackRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null) {
            return false;
        }

        $subject = FeedbackSubject::tryFrom((string) $this->input('subject_type'));

        return $subject === null || $subject->isOwnedBy($user, (int) $this->input('subject_id'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'subject_type' => ['required', Rule::enum(FeedbackSubject::class)],
            'subject_id' => ['required', 'integer', 'min:1'],
            'note' => ['nullable', 'string', 'max:'.Feedback::MAX_NOTE_LENGTH],
        ];
    }

    public function subject(): FeedbackSubject
    {
        return FeedbackSubject::from((string) $this->validated('subject_type'));
    }

    public function note(): ?string
    {
        $note = trim((string) $this->validated('note'));

        return $note === '' ? null : $note;
    }
}
