<?php

declare(strict_types=1);

namespace App\Services\Run\Story;

use App\Enums\Effort;
use App\Enums\IntentVerdict;
use App\Enums\Mood;
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
use App\Services\Weather\WeatherSnapshot;

class Temari
{
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

    private static function moodOf(Activity $activity, ActivityDetail $detail): Mood
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

    /** A 4-char sigil code; the renderer reads each char as a stitch op. */
    public static function sigilForMoodPublic(Mood $mood): string
    {
        return match ($mood) {
            Mood::Blazing => 'ssss',
            Mood::Easy => 'orct',
            Mood::Wobbly => 'fhfh',
            Mood::Gassed => 'wvwv',
            Mood::Overloaded => 'splr',
            Mood::Chill => 'dddd',
        };
    }

    /**
     * Mood an activity would carry once its post-run StoryLine is persisted, for
     * surfaces (share card, reveal) that render before narration lands. Returns the
     * rest-day default only when there's no detail to read.
     */
    public static function moodForActivityOrDefault(Activity $activity): Mood
    {
        $detail = $activity->detail;
        if ($detail === null) {
            return Mood::Chill;
        }

        return self::moodOf($activity, $detail);
    }

    private static function hasPr(Activity $activity): bool
    {
        return RunCard::query()->where('activity_id', $activity->id)->where('pr_set', true)->exists();
    }

    // Order matters — first matching rule wins, most-prestigious mood first.
    private static function moodForActivity(ActivityDetail $detail, bool $hasPr, Effort $effort, ?PlannedSession $planDay): Mood
    {
        $plannedType = $planDay === null ? null : EffectiveSession::settledTypeOf($planDay);
        $qualityDay = $planDay !== null && (in_array($plannedType, [SessionType::Tempo, SessionType::Interval, SessionType::Race], true)
            || ($plannedType === SessionType::Long && $planDay->prescribed_pace_band === PaceBand::Marathon && $planDay->prescribed_hard_minutes > 0));
        $summary = StreamSummary::fromArray($detail->streamSummary());
        $hardShare = $summary->hardZoneShare();
        $decoupling = $summary->steadyEffortDecouplingPct();
        $hotWeather = (int) ($detail->weather_temp_c ?? 0) >= WeatherSnapshot::HOT_RUN_TEMP_C;
        $negativeSplit = $summary->negativeSplit() === true;
        $hardSession = $hardShare >= 80.0;
        $intendedHard = SessionIntent::isIntendedHard($detail);
        $verdict = $planDay?->intent_verdict;
        $wentAfterQuality = $qualityDay
            && ($verdict === IntentVerdict::Hit || ($verdict !== IntentVerdict::Missed && $intendedHard));

        return match (true) {
            $hasPr => Mood::Blazing,
            // A hard session finished under control (strong negative split, HR held
            // together): a genuine win, not a grind.
            $hardSession && $negativeSplit && $decoupling !== null && $decoupling <= DecouplingBands::CONTROLLED => Mood::Blazing,
            // An intended-hard session (tagged race/workout, or inferred tempo) runs
            // HR/decoupling hot on purpose — that's the work, not weakness. A strong
            // finish is a quality win (blazing); an uncontrolled grind is honest overreach
            // (overloaded), never the tired 'gassed'.
            $intendedHard && $decoupling !== null && $decoupling > DecouplingBands::HIGH => $negativeSplit ? Mood::Blazing : Mood::Overloaded,
            // HR drifted well past pace on a run that wasn't meant to be hard.
            $decoupling !== null && $decoupling > DecouplingBands::HIGH => Mood::Gassed,
            $hotWeather => Mood::Wobbly,
            // A quality session is judged by its purpose: run harder than asked is
            // overreach, done as asked (or showing the threshold work) is a win.
            $verdict === IntentVerdict::TooHard => Mood::Overloaded,
            $wentAfterQuality => Mood::Blazing,
            // A hard grind that never settled into a controlled finish.
            $hardSession && ! $negativeSplit => Mood::Overloaded,
            // Finished strong (a hard-but-controlled session lands here too, since
            // an uncontrolled hard session was already caught as overloaded above).
            $negativeSplit => Mood::Easy,
            // Chill is the rest-day mood, so a run the effort scale reads as
            // steady or hard never falls back to it.
            $effort === Effort::Hard => Mood::Blazing,
            $effort === Effort::Steady => Mood::Easy,
            default => Mood::Chill,
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

    public function moodForVibe(string $vibe): Mood
    {
        return match ($vibe) {
            Vibe::PUMPED, Vibe::FRESH => Mood::Blazing,
            Vibe::BOUNCY => Mood::Easy,
            Vibe::WORN_DOWN => Mood::Gassed,
            Vibe::COOKED => Mood::Wobbly,
            Vibe::STRETCHED_THIN => Mood::Overloaded,
            Vibe::HIBERNATING => Mood::Chill,
            default => Mood::Chill,
        };
    }
}
