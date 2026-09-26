<?php

declare(strict_types=1);

use App\Models\StravaGrantToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;

uses(RefreshDatabase::class);

it('encrypts the mirrored token and hides it from serialization', function (): void {
    $token = new StravaGrantToken([
        'strava_athlete_id' => '987654',
        'credential_version' => '4',
        'refresh_token' => 'plain-refresh-token',
    ]);
    $stored = $token->getAttributes()['refresh_token'];

    expect($token->strava_athlete_id)->toBe(987654)
        ->and($token->credential_version)->toBe(4)
        ->and($stored)->not->toBe('plain-refresh-token')
        ->and(Crypt::decryptString($stored))->toBe('plain-refresh-token')
        ->and($token->refresh_token)->toBe('plain-refresh-token')
        ->and($token->toArray())->not->toHaveKey('refresh_token');
});

it('keeps an orphaned token and its user id after account deletion', function (): void {
    $user = User::factory()->create();
    $token = StravaGrantToken::query()->create([
        'strava_athlete_id' => 987654,
        'user_id' => $user->id,
        'credential_version' => 0,
        'refresh_token' => 'refresh-token',
    ]);

    $user->delete();

    expect($token->fresh()->user_id)->toBe($user->id)
        ->and($token->fresh()->refresh_token)->toBe('refresh-token');
});
