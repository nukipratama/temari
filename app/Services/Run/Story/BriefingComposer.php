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
        $hoursSince = $this->hoursSinceLastRun($user, $asOf);
        $daysSince = $hoursSince === null ? null : (int) floor($hoursSince / 24);

        $mood = $this->temari->moodForVibe($vibeState);
        $discriminator = $asOf->toDateString();
        $subjectType = AnalysisType::BRIEFING_SUBJECT_TYPE;

        $headline = $this->existingRow($user, AnalysisType::BriefingHeadline, $subjectType, $discriminator);
        $suggestion = $this->existingRow($user, AnalysisType::BriefingSuggestion, $subjectType, $discriminator);
        $mascotVoice = $this->existingRow($user, AnalysisType::BriefingMascotVoice, $subjectType, $discriminator);
        $featuredKartuVoice = $this->existingRow($user, AnalysisType::BriefingFeaturedKartuVoice, $subjectType, $discriminator);

        return new BriefingResult(
            vibeState: $vibeState,
            vibeLabel: Vibe::label($vibeState),
            vibeEmoji: Vibe::emoji($vibeState),
            headline: Analysis::toPayload($headline, AnalysisType::BriefingHeadline, $subjectType, $user->id, $discriminator),
            suggestion: Analysis::toPayload($suggestion, AnalysisType::BriefingSuggestion, $subjectType, $user->id, $discriminator),
            mascotVoice: Analysis::toPayload($mascotVoice, AnalysisType::BriefingMascotVoice, $subjectType, $user->id, $discriminator),
            featuredKartuVoice: Analysis::toPayload($featuredKartuVoice, AnalysisType::BriefingFeaturedKartuVoice, $subjectType, $user->id, $discriminator),
            recoveryLabel: FormStatus::label($load),
            recoveryTone: FormStatus::tone($load),
            recoveryHoursLabel: $this->recoveryHoursLabel($hoursSince),
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

    private function hoursSinceLastRun(User $user, Carbon $asOf): ?int
    {
        $lastRun = ActivityDetail::query()
            ->whereHas('activity', fn ($q) => $q->where('user_id', $user->id))
            ->whereNotNull('start_date_local')
            ->orderByDesc('start_date_local')
            ->value('start_date_local');

        if ($lastRun === null) {
            return null;
        }

        // Hours between the last run's local timestamp and `now` on the same
        // local clock (asOf is start-of-day; bump to now-of-day for precision).
        $now = $asOf->isSameDay(Carbon::now()) ? Carbon::now() : $asOf->copy()->endOfDay();

        return max(0, (int) Carbon::parse($lastRun)->diffInHours($now, absolute: true));
    }

    private function recoveryHoursLabel(?int $hoursSince): ?string
    {
        if ($hoursSince === null) {
            return null;
        }
        if ($hoursSince < 72) {
            return "{$hoursSince} jam";
        }
        $days = (int) floor($hoursSince / 24);

        return "{$days} hari";
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
