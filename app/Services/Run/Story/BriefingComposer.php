<?php

declare(strict_types=1);

namespace App\Services\Run\Story;

use App\Models\AI\Analysis;
use App\Models\User;
use App\Services\AI\AnalysisStatus;
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

        [$mascotVoice, $everNarrated] = $this->briefingState($user, $subjectType, $discriminator);

        return new BriefingResult(
            vibeState: $vibeState,
            vibeLabel: Vibe::label($vibeState),
            vibeEmoji: Vibe::emoji($vibeState),
            mascotVoice: Analysis::toPayload($mascotVoice, AnalysisType::BriefingMascotVoice, $subjectType, $user->id, $discriminator),
            firstRead: ! $everNarrated,
            recoveryLabel: FormStatus::label($load),
            recoveryTone: FormStatus::tone($load),
            recoveryHoursLabel: $this->recoveryHoursLabel($hoursSince),
            recoveryHours: $hoursSince,
            streakLabel: $this->streakLabel($daysSince),
            sigilPattern: Temari::sigilForMoodPublic($mood),
            mood: $mood,
        );
    }

    /**
     * Today's briefing row and whether this athlete has ever had one narrated,
     * in a single read: the Today card says "temari is reading your first
     * week…" while the very first briefing is still being written, and stays
     * silent for every pending briefing after it.
     *
     * One query, ordered so today's row comes first and capped at two, because
     * the only other row worth carrying back is a single Done one — anything
     * more would grow with the account's age for an answer that is a boolean.
     *
     * @return array{0: ?Analysis, 1: bool}
     */
    private function briefingState(User $user, string $subjectType, string $discriminator): array
    {
        $rows = Analysis::query()
            ->where('subject_type', $subjectType)
            ->where('subject_id', $user->id)
            ->where('analysis_type', AnalysisType::BriefingMascotVoice)
            ->where(function ($query) use ($discriminator): void {
                $query->where('discriminator', $discriminator)
                    ->orWhere('status', AnalysisStatus::Done);
            })
            ->orderByRaw('discriminator <=> ? desc', [$discriminator])
            ->limit(2)
            ->get();

        $today = $rows->first(fn (Analysis $row): bool => $row->discriminator === $discriminator);
        $everNarrated = $rows->contains(fn (Analysis $row): bool => $row->status === AnalysisStatus::Done);

        return [$today, $everNarrated];
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
