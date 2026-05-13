<?php

declare(strict_types=1);

namespace App\Services\Run\Story;

use App\Models\ActivityDetail;
use App\Models\User;
use App\Services\Run\Metrics\TrainingLoad;
use App\Services\Run\Story\Contracts\BriefingNarrator;
use Illuminate\Support\Carbon;

/**
 * Builds the dashboard hero "Briefing Temari" — a 2-line plan-of-the-day
 * with chips for recovery + streak. Synthesizes Vibe + TrainingLoad +
 * days-since-last-run into one render-ready DTO so the view stays dumb.
 *
 * Pre-run weather is intentionally not included: we don't know where the
 * user will run next, so current weather at any single location is a weak
 * proxy. Accessory cues come from Temari's mood, not real conditions.
 */
class Briefing implements BriefingNarrator
{
    public function __construct(
        private readonly Vibe $vibe,
        private readonly TrainingLoad $trainingLoad,
    ) {
    }

    public function generate(User $user, ?Carbon $asOf = null): BriefingResult
    {
        $asOf ??= Carbon::today();
        $vibeState = $this->vibe->current($user, $asOf);
        $load = $this->trainingLoad->summary($user, $asOf);
        $daysSince = $this->daysSinceLastRun($user, $asOf);

        $mood = $this->moodForVibe($vibeState);

        return new BriefingResult(
            vibeState: $vibeState,
            vibeLabel: Vibe::label($vibeState),
            vibeEmoji: Vibe::emoji($vibeState),
            headlineLine: $this->headlineFor($vibeState),
            suggestionLine: $this->suggestionFor($vibeState, $daysSince),
            recoveryLabel: FormStatus::label($load),
            recoveryTone: FormStatus::tone($load),
            streakLabel: $this->streakLabel($daysSince),
            sigilPattern: $this->sigilForMood($mood),
            accessory: $this->accessoryForMood($mood),
            mood: $mood,
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

    private function headlineFor(string $vibe): string
    {
        $label = Vibe::label($vibe);

        return match ($vibe) {
            Vibe::PUMPED, Vibe::FRESH, Vibe::BOUNCY => "Vibe hari ini: {$label}.",
            Vibe::WORN_DOWN, Vibe::COOKED, Vibe::STRETCHED_THIN => "Vibe hari ini: {$label}.",
            Vibe::HIBERNATING => "Vibe hari ini: {$label}.",
            default => "Vibe hari ini: {$label}.",
        };
    }

    private function suggestionFor(string $vibe, ?int $daysSince): string
    {
        if ($daysSince !== null && $daysSince >= 5) {
            return 'Sudah lama nggak lari — keluar dulu yuk, easy 3K aja.';
        }

        return match ($vibe) {
            Vibe::PUMPED => 'Rencana: manfaatin momentum, sesi tempo bisa jalan.',
            Vibe::FRESH => 'Rencana: easy run aja, jaga taper.',
            Vibe::BOUNCY => 'Rencana: aerobic enak — pertahankan ritme.',
            Vibe::STEADY => 'Rencana: easy 5K, konsisten = juara jangka panjang.',
            Vibe::WORN_DOWN => 'Rencana: pertimbangkan recovery run atau easy day.',
            Vibe::COOKED => 'Rencana: rest day. Bukan kelemahan — itu strategi.',
            Vibe::STRETCHED_THIN => 'Rencana: volume udah cukup, jangan dipaksa kecepatan.',
            Vibe::HIBERNATING => 'Rencana: saatnya keluar pintu lagi. Easy 3K aja.',
            default => 'Rencana: easy run, satu langkah satu langkah.',
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

    private function sigilForMood(string $mood): string
    {
        return Temari::sigilForMoodPublic($mood);
    }

    private function accessoryForMood(string $mood): ?string
    {
        return Temari::accessoryForMoodPublic($mood);
    }
}
