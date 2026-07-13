<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use App\Models\Analytics\StravaSyncLog;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Override;

/**
 * @property bool $is_demo
 * @property bool $is_admin
 * @property int|null $pending_reveal_card_id
 */
// `is_admin` is deliberately NOT fillable: it is a privilege flag granted only
// via the `user:set-admin` command, never through mass assignment.
#[Fillable(['name', 'email', 'avatar_url', 'is_demo', 'pending_reveal_card_id'])]
#[Hidden(['remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;
    use Notifiable;

    #[Override]
    protected static function booted(): void
    {
        static::deleting(function (User $user): void {
            $connection = $user->stravaConnection;

            if ($connection !== null && ! $connection->isRevoked()) {
                $connection->markRevoked();
            }

            StravaSyncLog::log($user->id, 'deleted', error: 'User model deleted');

            Log::info('strava user deleted — connection revoked and sync log written', [
                'user_id' => $user->id,
            ]);
        });
    }

    /** @return array<string, string> */
    #[Override]
    protected function casts(): array
    {
        return [
            'is_demo' => 'boolean',
            'is_admin' => 'boolean',
        ];
    }

    /**
     * Excludes the seeded demo account so scheduled work (strava:sync/ingest, the
     * AI recaps) never spends a real Strava call or LLM token on it. Local (not
     * global) so the demo stays fully visible in the app UI. Relation sub-queries
     * filter on `is_demo` directly (`whereHas('user', fn ($q) => $q->where(...))`)
     * since the scope isn't visible on a generically-typed related builder.
     *
     * @param  Builder<User>  $query
     */
    #[Scope]
    protected function notDemo(Builder $query): void
    {
        $query->where('is_demo', false);
    }

    /**
     * @return HasOne<StravaConnection, $this>
     */
    public function stravaConnection(): HasOne
    {
        return $this->hasOne(StravaConnection::class);
    }

    /**
     * @return HasOne<RunnerProfile, $this>
     */
    public function runnerProfile(): HasOne
    {
        return $this->hasOne(RunnerProfile::class);
    }

    /**
     * @return HasOne<TelegramConnection, $this>
     */
    public function telegramConnection(): HasOne
    {
        return $this->hasOne(TelegramConnection::class);
    }

    /**
     * Fixed public contract for heart-rate and cadence settings. Returns the
     * stored runner_profiles row when present, otherwise the config('runner.*')
     * defaults in the identical shape so callers cannot tell the difference.
     *
     * @return array{max_hr:int, resting_hr:int, hr_zones:array<string,array{lo:int,hi:int}>, optimal_cadence_spm:int}
     */
    public function hrProfile(): array
    {
        $profile = $this->runnerProfile;

        if ($profile !== null) {
            return [
                'max_hr' => $profile->max_hr,
                'resting_hr' => $profile->resting_hr,
                'hr_zones' => $profile->hr_zones,
                'optimal_cadence_spm' => $profile->optimal_cadence_spm,
            ];
        }

        /** @var array<string, array{lo:int, hi:int}> $hrZones */
        $hrZones = config('runner.hr_zones');

        return [
            'max_hr' => (int) config('runner.max_hr'),
            'resting_hr' => (int) config('runner.resting_hr'),
            'hr_zones' => $hrZones,
            'optimal_cadence_spm' => (int) config('runner.optimal_cadence_spm'),
        ];
    }

    /**
     * @return HasMany<Activity, $this>
     */
    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class);
    }

    /**
     * @return HasMany<PersonalRecord, $this>
     */
    public function personalRecords(): HasMany
    {
        return $this->hasMany(PersonalRecord::class);
    }

    /**
     * @return HasMany<WeeklySnapshot, $this>
     */
    public function weeklySnapshots(): HasMany
    {
        return $this->hasMany(WeeklySnapshot::class);
    }

    /**
     * @return HasMany<StoryLine, $this>
     */
    public function storyLines(): HasMany
    {
        return $this->hasMany(StoryLine::class);
    }

    /**
     * The first whitespace token of the Strava display name, sanitized before it
     * flows into LLM prompts. Strips CR/LF and caps length so a hostile profile
     * name cannot inject instructions into a narrator prompt.
     */
    public function firstName(): string
    {
        $token = preg_split('/\s+/', trim((string) $this->name), 2)[0] ?? '';
        $token = str_replace(["\r", "\n"], '', $token);

        return Str::limit($token, 40, '');
    }
}
