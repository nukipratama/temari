<?php

declare(strict_types=1);

namespace App\Services\Run\Story;

use App\Actions\Run\Story\ResolveFeaturedKartuAction;
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
        private readonly ResolveFeaturedKartuAction $featuredKartu,
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

        $mascotVoice = $this->existingRow($user, AnalysisType::BriefingMascotVoice, $subjectType, $discriminator);

        // The featured-kartu voice keys off the card it describes (not the day),
        // so the hero card and its quote stay in lockstep even as new runs slide
        // the pick. No featured card -> no row, and the panel shows its empty state.
        $featuredCard = ($this->featuredKartu)($user);
        $featuredDiscriminator = $featuredCard !== null ? (string) $featuredCard->id : null;
        $featuredKartuVoice = $featuredDiscriminator !== null
            ? $this->existingRow($user, AnalysisType::BriefingFeaturedKartuVoice, $subjectType, $featuredDiscriminator)
            : null;

        return new BriefingResult(
            vibeState: $vibeState,
            vibeLabel: Vibe::label($vibeState),
            vibeEmoji: Vibe::emoji($vibeState),
            mascotVoice: Analysis::toPayload($mascotVoice, AnalysisType::BriefingMascotVoice, $subjectType, $user->id, $discriminator),
            featuredKartuVoice: Analysis::toPayload($featuredKartuVoice, AnalysisType::BriefingFeaturedKartuVoice, $subjectType, $user->id, $featuredDiscriminator),
            featuredCardId: $featuredCard?->id,
            recoveryLabel: FormStatus::label($load),
            recoveryTone: FormStatus::tone($load),
            recoveryHoursLabel: $this->recoveryHoursLabel($hoursSince),
            recoveryHours: $hoursSince,
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
        return RecoveryWindow::forUser($user, $asOf)->hoursSinceLastRun;
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
