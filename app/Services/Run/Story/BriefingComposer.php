<?php

declare(strict_types=1);

namespace App\Services\Run\Story;

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

        $mascotVoice = $this->existingRow($user, AnalysisType::BriefingMascotVoice, $subjectType, $discriminator);

        return new BriefingResult(
            vibeState: $vibeState,
            vibeLabel: Vibe::label($vibeState),
            vibeEmoji: Vibe::emoji($vibeState),
            mascotVoice: Analysis::toPayload($mascotVoice, AnalysisType::BriefingMascotVoice, $subjectType, $user->id, $discriminator),
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
            return "{$hoursSince}h";
        }
        $days = (int) floor($hoursSince / 24);

        return "{$days} days";
    }

    private function streakLabel(?int $daysSince): ?string
    {
        return match (true) {
            $daysSince === null => null,
            $daysSince === 0 => 'Ran today',
            $daysSince === 1 => 'Ran yesterday',
            default => "{$daysSince} days ago",
        };
    }
}
