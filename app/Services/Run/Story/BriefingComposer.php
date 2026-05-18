<?php

declare(strict_types=1);

namespace App\Services\Run\Story;

use App\Models\ActivityDetail;
use App\Models\AI\Analysis;
use App\Models\User;
use App\Services\AI\AnalysisService;
use App\Services\AI\AnalysisType;
use App\Services\Run\Metrics\TrainingLoad;
use Illuminate\Support\Carbon;

class BriefingComposer
{
    /** @deprecated Use {@see AnalysisType::BRIEFING_SUBJECT_TYPE}. */
    public const string SUBJECT_TYPE = AnalysisType::BRIEFING_SUBJECT_TYPE;

    public function __construct(
        private readonly Vibe $vibe,
        private readonly TrainingLoad $trainingLoad,
        private readonly AnalysisService $analysisService,
    ) {
    }

    public function compose(User $user, ?Carbon $asOf = null): BriefingResult
    {
        $asOf ??= Carbon::today();
        $vibeState = $this->vibe->current($user, $asOf);
        $load = $this->trainingLoad->summary($user, $asOf);
        $daysSince = $this->daysSinceLastRun($user, $asOf);

        $mood = $this->moodForVibe($vibeState);
        $discriminator = $asOf->toDateString();
        $subjectType = AnalysisType::BRIEFING_SUBJECT_TYPE;

        $headline = $this->resolveRow($user, AnalysisType::BriefingHeadline, $subjectType, $discriminator);
        $suggestion = $this->resolveRow($user, AnalysisType::BriefingSuggestion, $subjectType, $discriminator);

        return new BriefingResult(
            vibeState: $vibeState,
            vibeLabel: Vibe::label($vibeState),
            vibeEmoji: Vibe::emoji($vibeState),
            headline: Analysis::toPayload($headline, AnalysisType::BriefingHeadline, $subjectType, $user->id, $discriminator),
            suggestion: Analysis::toPayload($suggestion, AnalysisType::BriefingSuggestion, $subjectType, $user->id, $discriminator),
            recoveryLabel: FormStatus::label($load),
            recoveryTone: FormStatus::tone($load),
            streakLabel: $this->streakLabel($daysSince),
            sigilPattern: Temari::sigilForMoodPublic($mood),
            accessory: Temari::accessoryForMoodPublic($mood),
            mood: $mood,
        );
    }

    private function resolveRow(User $user, AnalysisType $type, string $subjectType, string $discriminator): Analysis
    {
        $row = Analysis::query()
            ->forSubject($subjectType, $user->id, $type, $discriminator)
            ->first();

        return $row ?? $this->analysisService->request(
            subjectOrType: $subjectType,
            subjectId: $user->id,
            type: $type,
            discriminator: $discriminator,
        );
    }

    private function daysSinceLastRun(User $user, Carbon $asOf): ?int
    {
        $lastRun = ActivityDetail::query()
            ->whereHas('activity', fn ($q) => $q->where('user_id', $user->id))
            ->whereNotNull('start_date_local')
            ->orderByDesc('start_date_local')
            ->value('start_date_local');

        if ($lastRun === null) {
            return null;
        }

        return (int) Carbon::parse($lastRun)->startOfDay()->diffInDays($asOf->copy()->startOfDay());
    }

    private function streakLabel(?int $daysSince): ?string
    {
        if ($daysSince === null) {
            return null;
        }

        return match (true) {
            $daysSince === 0 => 'Lari hari ini',
            $daysSince === 1 => 'Kemarin lari',
            $daysSince <= 3 => "Sudah {$daysSince} hari",
            default => "Sudah {$daysSince} hari nih",
        };
    }

    private function moodForVibe(string $vibe): string
    {
        return match ($vibe) {
            Vibe::PUMPED, Vibe::FRESH => Temari::MOOD_GLOW,
            Vibe::BOUNCY => Temari::MOOD_BOUNCY,
            Vibe::WORN_DOWN => Temari::MOOD_WOBBLE,
            Vibe::COOKED => Temari::MOOD_SQUISHED,
            Vibe::STRETCHED_THIN => Temari::MOOD_SPINNING,
            Vibe::HIBERNATING => Temari::MOOD_DIM,
            default => Temari::MOOD_DIM,
        };
    }
}
