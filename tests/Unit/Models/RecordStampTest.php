<?php

declare(strict_types=1);

use App\Models\RecordStamp;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

it('casts user_id to int and seen_at to Carbon', function (): void {
    $user = User::factory()->create();
    $stamp = RecordStamp::query()->create([
        'user_id' => (string) $user->id,
        'record_key' => '10km',
        'seen_at' => '2026-04-09 00:00:00',
    ]);

    expect($stamp->user_id)->toBeInt()
        ->and($stamp->seen_at)->toBeInstanceOf(Carbon::class);
});

it('belongs to a user', function (): void {
    $user = User::factory()->create();
    $stamp = RecordStamp::query()->create([
        'user_id' => $user->id,
        'record_key' => 'longest_run',
        'seen_at' => now(),
    ]);

    expect($stamp->user->is($user))->toBeTrue();
});

it('enforces one stamp per (user_id, record_key)', function (): void {
    $user = User::factory()->create();
    RecordStamp::query()->create(['user_id' => $user->id, 'record_key' => '10km', 'seen_at' => now()]);

    expect(fn () => RecordStamp::query()->create(['user_id' => $user->id, 'record_key' => '10km', 'seen_at' => now()]))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('allows the same record key under different users', function (): void {
    RecordStamp::query()->create(['user_id' => User::factory()->create()->id, 'record_key' => '10km', 'seen_at' => now()]);
    $second = RecordStamp::query()->create(['user_id' => User::factory()->create()->id, 'record_key' => '10km', 'seen_at' => now()]);

    expect($second->record_key)->toBe('10km');
});

it('is deleted when its user is deleted', function (): void {
    $user = User::factory()->create();
    $stamp = RecordStamp::query()->create(['user_id' => $user->id, 'record_key' => '10km', 'seen_at' => now()]);

    $user->delete();

    expect(RecordStamp::query()->whereKey($stamp->id)->exists())->toBeFalse();
});
