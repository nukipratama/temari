<?php

declare(strict_types=1);

namespace App\Services\Run\Story;

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\PersonalRecord;
use App\Models\StoryLine;
use App\Models\User;
use App\Services\Run\Metrics\SessionIntent;
use App\Services\Run\Metrics\StreamSummary;
use Illuminate\Support\Carbon;

class Temari
{
    // Daybreak mood vocabulary — see [README handoff §Mood Vocabulary].
    public const MOOD_NYALA = 'nyala';     // PR / hard win

    public const MOOD_ENTENG = 'enteng';   // easy run / negative split

    public const MOOD_OLENG = 'oleng';     // HR drift / heat strain (was squished slot)

    public const MOOD_LEMES = 'lemes';     // wobble / decoupling drift

    public const MOOD_MUMET = 'mumet';     // overreaching / hard-zone heavy

    public const MOOD_ADEM = 'adem';       // rest day / default

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
        $mood = self::moodForActivity($detail, self::hasPr($activity));

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

    public static function accessoryForMoodPublic(string $mood): ?string
    {
        return match ($mood) {
            self::MOOD_NYALA => 'headband',
            self::MOOD_ENTENG => null,
            self::MOOD_ADEM => 'mata-ngantuk',
            default => null,
        };
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

        return self::moodForActivity($detail, self::hasPr($activity));
    }

    private static function hasPr(Activity $activity): bool
    {
        return PersonalRecord::query()->where('activity_id', $activity->id)->exists();
    }

    // Order matters — first matching rule wins, most-prestigious mood first.
    private static function moodForActivity(ActivityDetail $detail, bool $hasPr): string
    {
        $summary = $detail->streamSummary();
        $hardShare = StreamSummary::hardZoneShare($summary);
        $decoupling = (float) ($summary['decoupling_pct'] ?? 0);
        $hotWeather = (int) ($detail->weather_temp_c ?? 0) >= 31;
        $negativeSplit = ($summary['negative_split'] ?? false) === true;
        $hardSession = $hardShare >= 80.0;
        $intendedHard = SessionIntent::isIntendedHard($detail);

        return match (true) {
            $hasPr => self::MOOD_NYALA,
            // A hard session finished under control (strong negative split, HR held
            // together): a genuine win, not a grind.
            $hardSession && $negativeSplit && $decoupling <= 5.0 => self::MOOD_NYALA,
            // An intended-hard session (tagged race/workout, or inferred tempo) runs
            // HR/decoupling hot on purpose — that's the work, not weakness. A strong
            // finish is a quality win (nyala); an uncontrolled grind is honest overreach
            // (mumet), never the tired 'lemes'.
            $intendedHard && $decoupling > 12.0 => $negativeSplit ? self::MOOD_NYALA : self::MOOD_MUMET,
            // HR drifted well past pace on a run that wasn't meant to be hard.
            $decoupling > 12.0 => self::MOOD_LEMES,
            $hotWeather => self::MOOD_OLENG,
            // A hard grind that never settled into a controlled finish.
            $hardSession && ! $negativeSplit => self::MOOD_MUMET,
            // Finished strong (a hard-but-controlled session lands here too, since
            // an uncontrolled hard session was already caught as mumet above).
            $negativeSplit => self::MOOD_ENTENG,
            default => self::MOOD_ADEM,
        };
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
