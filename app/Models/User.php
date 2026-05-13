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

#[Fillable(['name', 'email', 'avatar_url'])]
#[Hidden(['remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;
    use Notifiable;

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

    /**
     * First word of the user's name — used wherever we greet the user
     * informally (dashboard header, Temari prompts). Single source of
     * truth so the rule (whitespace split, first token) stays consistent.
     */
    public function firstName(): string
    {
        return explode(' ', (string) $this->name)[0];
    }
}
