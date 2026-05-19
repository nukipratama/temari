<?php

declare(strict_types=1);

namespace App\Services\Run\Story;

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\PersonalRecord;
use App\Models\StoryLine;
use App\Models\User;
use App\Services\AI\AnalysisType;
use App\Services\AI\AnalysisService;
use App\Services\Run\Metrics\StreamSummary;
use Illuminate\Support\Carbon;

use function is_array;

class Temari
{
    public const MOOD_BOUNCY = 'bouncy';

    public const MOOD_GLOW = 'glow';

    public const MOOD_WOBBLE = 'wobble';

    public const MOOD_DIM = 'dim';

    public const MOOD_SPINNING = 'spinning';

    public const MOOD_SQUISHED = 'squished';

    /** @deprecated Use {@see AnalysisType::DAILY_GREETING_SUBJECT_TYPE}. Kept for back-compat. */
    public const string DAILY_GREETING_SUBJECT_TYPE = AnalysisType::DAILY_GREETING_SUBJECT_TYPE;

    // 4-char sigil codes; renderer reads each char as a stitch op.
    private const array SIGIL_FOR_MOOD = [
        self::MOOD_BOUNCY => 'orct',
        self::MOOD_GLOW => 'ssss',
        self::MOOD_WOBBLE => 'wvwv',
        self::MOOD_DIM => 'dddd',
        self::MOOD_SPINNING => 'splr',
        self::MOOD_SQUISHED => 'fhfh',
    ];

    public function __construct(private readonly AnalysisService $analysisService)
    {
    }

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

        $line = StoryLine::query()->updateOrCreate(
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

        $this->analysisService->request(
            subjectOrType: self::DAILY_GREETING_SUBJECT_TYPE,
            subjectId: $user->id,
            type: AnalysisType::DailyGreeting,
            discriminator: $date,
        );

        return $line;
    }

    public static function sigilForMoodPublic(string $mood): string
    {
        return self::SIGIL_FOR_MOOD[$mood] ?? self::SIGIL_FOR_MOOD[self::MOOD_DIM];
    }

    public static function accessoryForMoodPublic(string $mood): ?string
    {
        return match ($mood) {
            self::MOOD_GLOW => 'headband',
            self::MOOD_BOUNCY => 'pita',
            self::MOOD_DIM => 'mata-ngantuk',
            default => null,
        };
    }

    // Order matters — first matching rule wins, most-prestigious mood first.
    private function moodForActivity(ActivityDetail $detail, bool $hasPr): string
    {
        $summary = is_array($detail->stream_summary) ? $detail->stream_summary : [];
        $hardShare = StreamSummary::hardZoneShare($summary);
        $decoupling = (float) ($summary['decoupling_pct'] ?? 0);
        $hotWeather = (int) ($detail->weather_temp_c ?? 0) >= 31;

        return match (true) {
            $hasPr => self::MOOD_GLOW,
            $hardShare >= 50.0 => self::MOOD_SPINNING,
            $decoupling > 8.0 => self::MOOD_WOBBLE,
            $hotWeather => self::MOOD_SQUISHED,
            ($summary['negative_split'] ?? false) === true => self::MOOD_BOUNCY,
            default => self::MOOD_DIM,
        };
    }

    public function moodForVibe(string $vibe): string
    {
        return match ($vibe) {
            Vibe::PUMPED, Vibe::FRESH => self::MOOD_GLOW,
            Vibe::BOUNCY => self::MOOD_BOUNCY,
            Vibe::WORN_DOWN => self::MOOD_WOBBLE,
            Vibe::COOKED => self::MOOD_SQUISHED,
            Vibe::STRETCHED_THIN => self::MOOD_SPINNING,
            Vibe::HIBERNATING => self::MOOD_DIM,
            default => self::MOOD_DIM,
        };
    }
}
