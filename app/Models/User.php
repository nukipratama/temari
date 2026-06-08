<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Analytics\StravaSyncLog;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Override;

/**
 * @property Carbon|null $last_seen_pr_ledger_at
 * @property int|null $pending_reveal_card_id
 */
#[Fillable(['name', 'email', 'avatar_url', 'last_seen_pr_ledger_at', 'pending_reveal_card_id'])]
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
            'last_seen_pr_ledger_at' => 'datetime',
        ];
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

    public function firstName(): string
    {
        return explode(' ', (string) $this->name)[0];
    }
}
