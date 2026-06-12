<?php

declare(strict_types=1);

namespace App\Services\Run\Story;

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\PersonalRecord;
use App\Models\StoryLine;
use App\Models\User;
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
        $hasPr = PersonalRecord::query()->where('activity_id', $activity->id)->exists();
        $mood = $this->moodForActivity($detail, $hasPr);

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

    // Order matters — first matching rule wins, most-prestigious mood first.
    private function moodForActivity(ActivityDetail $detail, bool $hasPr): string
    {
        $summary = $detail->streamSummary();
        $hardShare = StreamSummary::hardZoneShare($summary);
        $decoupling = (float) ($summary['decoupling_pct'] ?? 0);
        $hotWeather = (int) ($detail->weather_temp_c ?? 0) >= 31;

        return match (true) {
            $hasPr => self::MOOD_NYALA,
            $hardShare >= 50.0 => self::MOOD_MUMET,
            $decoupling > 8.0 => self::MOOD_LEMES,
            $hotWeather => self::MOOD_OLENG,
            ($summary['negative_split'] ?? false) === true => self::MOOD_ENTENG,
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
