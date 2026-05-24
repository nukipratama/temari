<?php

declare(strict_types=1);

namespace App\Services\Run\Story;

use App\Models\ActivityDetail;
use App\Models\AI\Analysis;
use App\Models\User;
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
        private readonly Temari $temari,
    ) {
    }

    public function compose(User $user, ?Carbon $asOf = null): BriefingResult
    {
        $asOf ??= Carbon::today();
        $vibeState = $this->vibe->current($user, $asOf);
        $load = $this->trainingLoad->summary($user, $asOf);
        $daysSince = $this->daysSinceLastRun($user, $asOf);

        $mood = $this->temari->moodForVibe($vibeState);
        $discriminator = $asOf->toDateString();
        $subjectType = AnalysisType::BRIEFING_SUBJECT_TYPE;

        $headline = $this->existingRow($user, AnalysisType::BriefingHeadline, $subjectType, $discriminator);
        $suggestion = $this->existingRow($user, AnalysisType::BriefingSuggestion, $subjectType, $discriminator);
        $mascotVoice = $this->existingRow($user, AnalysisType::BriefingMascotVoice, $subjectType, $discriminator);

        return new BriefingResult(
            vibeState: $vibeState,
            vibeLabel: Vibe::label($vibeState),
            vibeEmoji: Vibe::emoji($vibeState),
            headline: Analysis::toPayload($headline, AnalysisType::BriefingHeadline, $subjectType, $user->id, $discriminator),
            suggestion: Analysis::toPayload($suggestion, AnalysisType::BriefingSuggestion, $subjectType, $user->id, $discriminator),
            mascotVoice: Analysis::toPayload($mascotVoice, AnalysisType::BriefingMascotVoice, $subjectType, $user->id, $discriminator),
            recoveryLabel: FormStatus::label($load),
            recoveryTone: FormStatus::tone($load),
            streakLabel: $this->streakLabel($daysSince),
            sigilPattern: Temari::sigilForMoodPublic($mood),
            accessory: Temari::accessoryForMoodPublic($mood),
            mood: $mood,
        );
    }

    private function existingRow(User $user, AnalysisType $type, string $subjectType, string $discriminator): ?Analysis
    {
        return Analysis::query()
            ->forSubject($subjectType, $user->id, $type, $discriminator)
            ->first();
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
        return match (true) {
            $daysSince === null => null,
            $daysSince === 0 => 'Lari hari ini',
            $daysSince === 1 => 'Kemarin lari',
            default => "Sudah {$daysSince} hari",
        };
    }
}
