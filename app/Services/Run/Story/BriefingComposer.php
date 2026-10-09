<?php

declare(strict_types=1);

namespace App\Services\Run\Story;

use App\Models\AI\Analysis;
use App\Models\User;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use Illuminate\Support\Carbon;

class BriefingComposer
{
    public function __construct(
        private readonly Vibe $vibe,
        private readonly Temari $temari,
    ) {
    }

    public function compose(User $user, ?Carbon $asOf = null): BriefingResult
    {
        $asOf ??= Carbon::today();
        $mood = $this->temari->moodForVibe($this->vibe->current($user, $asOf));
        $discriminator = $asOf->toDateString();
        $subjectType = AnalysisType::BRIEFING_SUBJECT_TYPE;

        [$mascotVoice, $everNarrated] = $this->briefingState($user, $subjectType, $discriminator);

        return new BriefingResult(
            mascotVoice: Analysis::toPayload($mascotVoice, AnalysisType::BriefingMascotVoice, $subjectType, $user->id, $discriminator),
            firstRead: ! $everNarrated,
            mood: $mood,
        );
    }

    /**
     * Today's briefing row and whether this athlete has ever had one narrated,
     * in a single read. Ordered so today's row comes first and capped at two:
     * every other row in the filtered set is Done, so one of them settles the
     * boolean, and the read cannot grow with the account's age.
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
}
