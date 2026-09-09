<?php

declare(strict_types=1);

namespace App\Actions\Feedback;

use App\Enums\FeedbackSubject;
use App\Models\Feedback;
use Illuminate\Support\Facades\Auth;

/**
 * Everything the current athlete has already flagged, read once per request.
 *
 * Bound `scoped()` in AppServiceProvider: a plan week ships 7 day payloads and
 * a page can draw a dozen narration blocks, each of which needs to know whether
 * its own subject carries a flag. One set covers all of them.
 */
class ResolveFlaggedSubjectsAction
{
    /** @var array<int, array<string, true>> */
    private array $memo = [];

    public function __invoke(FeedbackSubject $subject, ?int $subjectId): bool
    {
        $userId = Auth::id();

        if ($subjectId === null || ! is_int($userId)) {
            return false;
        }

        return isset($this->flagsFor($userId)[$subject->value.':'.$subjectId]);
    }

    /**
     * @return array<string, true>
     */
    private function flagsFor(int $userId): array
    {
        if (array_key_exists($userId, $this->memo)) {
            return $this->memo[$userId];
        }

        $flags = [];
        foreach (Feedback::query()->where('user_id', $userId)->get(['subject_type', 'subject_id']) as $row) {
            $flags[$row->subject_type->value.':'.$row->subject_id] = true;
        }

        return $this->memo[$userId] = $flags;
    }
}
