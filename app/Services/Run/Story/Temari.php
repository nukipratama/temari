<?php

declare(strict_types=1);

namespace App\Services\Run\Story;

use App\Enums\Effort;
use App\Enums\IntentVerdict;
use App\Enums\PaceBand;
use App\Enums\SessionType;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\PlannedSession;
use App\Models\RunCard;
use App\Models\StoryLine;
use App\Models\User;
use App\Services\Run\Metrics\DecouplingBands;
use App\Services\Run\Metrics\RunEffort;
use App\Services\Run\Metrics\SessionIntent;
use App\Services\Run\Metrics\StreamSummary;
use App\Services\Run\Plan\EffectiveSession;
use App\Services\Run\Plan\PlannedSessionTypes;
use Illuminate\Support\Carbon;

class Temari
{
    // Threadwork mood vocabulary — see [README handoff §Mood Vocabulary].
    public const MOOD_NYALA = 'blazing';     // PR / hard win

    public const MOOD_ENTENG = 'easy';   // easy run / negative split

    public const MOOD_OLENG = 'wobbly';     // HR drift / heat strain

    public const MOOD_LEMES = 'gassed';     // wobble / decoupling drift

    public const MOOD_MUMET = 'overloaded';     // overreaching / hard-zone heavy

    public const MOOD_ADEM = 'chill';       // rest day / default

    // 4-char sigil codes; renderer reads each char as a stitch op.
    private const array SIGIL_FOR_MOOD = [
        self::MOOD_NYALA => 'ssss',
        self::MOOD_ENTENG => 'orct',
        self::MOOD_OLENG => 'fhfh',
        self::MOOD_LEMES => 'wvwv',
        self::MOOD_MUMET => 'splr',
        self::MOOD_ADEM => 'dddd',
    ];

    public function postRunLine(Activity $activity, ActivityDetail $detail): StoryLine
    {
        $mood = self::moodOf($activity, $detail);

        return StoryLine::query()->updateOrCreate(
            [
                'user_id' => $activity->user_id,
                'activity_id' => $activity->id,
            ],
            [
                'kind' => StoryLine::KIND_POST_RUN,
                'for_date' => null,
                'mood' => $mood,
                'speech' => null,
                'sigil_pattern' => self::sigilForMoodPublic($mood),
            ],
        );
    }

    /** Re-reads an existing post-run mood once the run's plan day is graded; never creates the line. */
    public function refreshPostRunMood(Activity $activity, ActivityDetail $detail): void
    {
        $line = StoryLine::query()
            ->where('activity_id', $activity->id)
            ->where('kind', StoryLine::KIND_POST_RUN)
            ->first();
        if ($line === null) {
            return;
        }

        $mood = self::moodOf($activity, $detail);
        if ($line->mood !== $mood) {
            $line->update(['mood' => $mood, 'sigil_pattern' => self::sigilForMoodPublic($mood)]);
        }
    }

    private static function moodOf(Activity $activity, ActivityDetail $detail): string
    {
        return self::moodForActivity($detail, self::hasPr($activity), self::effortOf($activity, $detail), self::planDayOf($activity, $detail));
    }

    public function dailyGreeting(User $user, string $vibe, ?Carbon $forDate = null): StoryLine
    {
        $date = $forDate?->toDateString() ?? Carbon::today()->toDateString();
        $mood = $this->moodForVibe($vibe);

        return StoryLine::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'for_date' => $date,
            ],
            [
                'kind' => StoryLine::KIND_DAILY_GREETING,
                'activity_id' => null,
                'mood' => $mood,
                'speech' => null,
                'sigil_pattern' => self::sigilForMoodPublic($mood),
            ],
        );
    }

    public static function sigilForMoodPublic(string $mood): string
    {
        return self::SIGIL_FOR_MOOD[$mood] ?? self::SIGIL_FOR_MOOD[self::MOOD_ADEM];
    }

    /**
     * Mood an activity would carry once its post-run StoryLine is persisted, for
     * surfaces (share card, reveal) that render before narration lands. Returns the
     * rest-day default only when there's no detail to read.
     */
    public static function moodForActivityOrDefault(Activity $activity): string
    {
        $detail = $activity->detail;
        if ($detail === null) {
            return self::MOOD_ADEM;
        }

        return self::moodOf($activity, $detail);
    }

    private static function hasPr(Activity $activity): bool
    {
        return RunCard::query()->where('activity_id', $activity->id)->where('pr_set', true)->exists();
    }

    // Order matters — first matching rule wins, most-prestigious mood first.
    private static function moodForActivity(ActivityDetail $detail, bool $hasPr, Effort $effort, ?PlannedSession $planDay): string
    {
        $plannedType = $planDay === null ? null : EffectiveSession::settledTypeOf($planDay);
        $qualityDay = $planDay !== null && (in_array($plannedType, [SessionType::Tempo, SessionType::Interval, SessionType::Race], true)
            || ($plannedType === SessionType::Long && $planDay->prescribed_pace_band === PaceBand::Marathon && $planDay->prescribed_hard_minutes > 0));
        $summary = StreamSummary::fromArray($detail->streamSummary());
        $hardShare = $summary->hardZoneShare();
        $decoupling = $summary->steadyEffortDecouplingPct();
        $hotWeather = (int) ($detail->weather_temp_c ?? 0) >= 31;
        $negativeSplit = $summary->negativeSplit() === true;
        $hardSession = $hardShare >= 80.0;
        $intendedHard = SessionIntent::isIntendedHard($detail);
        $verdict = $planDay?->intent_verdict;
        $wentAfterQuality = $qualityDay
            && ($verdict === IntentVerdict::Hit || ($verdict !== IntentVerdict::Missed && $intendedHard));

        return match (true) {
            $hasPr => self::MOOD_NYALA,
            // A hard session finished under control (strong negative split, HR held
            // together): a genuine win, not a grind.
            $hardSession && $negativeSplit && $decoupling !== null && $decoupling <= DecouplingBands::CONTROLLED => self::MOOD_NYALA,
            // An intended-hard session (tagged race/workout, or inferred tempo) runs
            // HR/decoupling hot on purpose — that's the work, not weakness. A strong
            // finish is a quality win (blazing); an uncontrolled grind is honest overreach
            // (overloaded), never the tired 'gassed'.
            $intendedHard && $decoupling !== null && $decoupling > DecouplingBands::HIGH => $negativeSplit ? self::MOOD_NYALA : self::MOOD_MUMET,
            // HR drifted well past pace on a run that wasn't meant to be hard.
            $decoupling !== null && $decoupling > DecouplingBands::HIGH => self::MOOD_LEMES,
            $hotWeather => self::MOOD_OLENG,
            // A quality session is judged by its purpose: run harder than asked is
            // overreach, done as asked (or showing the threshold work) is a win.
            $verdict === IntentVerdict::TooHard => self::MOOD_MUMET,
            $wentAfterQuality => self::MOOD_NYALA,
            // A hard grind that never settled into a controlled finish.
            $hardSession && ! $negativeSplit => self::MOOD_MUMET,
            // Finished strong (a hard-but-controlled session lands here too, since
            // an uncontrolled hard session was already caught as overloaded above).
            $negativeSplit => self::MOOD_ENTENG,
            // Chill is the rest-day mood, so a run the effort scale reads as
            // steady or hard never falls back to it.
            $effort === Effort::Hard => self::MOOD_NYALA,
            $effort === Effort::Steady => self::MOOD_ENTENG,
            default => self::MOOD_ADEM,
        };
    }

    private static function planDayOf(Activity $activity, ActivityDetail $detail): ?PlannedSession
    {
        $date = $detail->start_date_local;
        if ($date === null) {
            return null;
        }

        return PlannedSessionTypes::sessionsByDate($activity->user_id, $date->copy()->startOfDay(), $date->copy()->endOfDay())[$date->toDateString()] ?? null;
    }

    /** The same effort the run's colour shows, from {@see RunEffort}. */
    private static function effortOf(Activity $activity, ActivityDetail $detail): Effort
    {
        return RunEffort::forDetails($activity->user_id, collect([$detail]))[$activity->id] ?? Effort::Unknown;
    }

    public function moodForVibe(string $vibe): string
    {
        return match ($vibe) {
            Vibe::PUMPED, Vibe::FRESH => self::MOOD_NYALA,
            Vibe::BOUNCY => self::MOOD_ENTENG,
            Vibe::WORN_DOWN => self::MOOD_LEMES,
            Vibe::COOKED => self::MOOD_OLENG,
            Vibe::STRETCHED_THIN => self::MOOD_MUMET,
            Vibe::HIBERNATING => self::MOOD_ADEM,
            default => self::MOOD_ADEM,
        };
    }
}
