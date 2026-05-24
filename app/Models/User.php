<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
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
