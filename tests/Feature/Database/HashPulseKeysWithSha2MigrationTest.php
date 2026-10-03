<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

function pulseKeyHashExpression(string $table): string
{
    return (string) DB::scalar(
        'SELECT GENERATION_EXPRESSION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
        [$table, 'key_hash'],
    );
}

function pulseTables(): array
{
    return ['pulse_values', 'pulse_entries', 'pulse_aggregates'];
}

function hashPulseKeysMigration(): object
{
    return require base_path('database/migrations/2026_10_03_000100_hash_pulse_keys_with_sha2.php');
}

it('creates every Pulse key_hash from sha2, which MySQL 9.7 still allows in generated columns', function (): void {
    foreach (pulseTables() as $table) {
        expect(pulseKeyHashExpression($table))->toContain('sha2')->not->toContain('md5');
    }
});

it('rehashes an md5 key_hash with sha2 and keeps its rows and unique index', function (): void {
    if ((int) DB::scalar('SELECT VERSION()') >= 9) {
        $this->markTestSkipped('MySQL 9 rejects md5 in generated columns, so the pre-upgrade shape cannot be built.');
    }

    try {
        DB::statement('ALTER TABLE `pulse_values` MODIFY `key_hash` BINARY(16) GENERATED ALWAYS AS (unhex(md5(`key`))) VIRTUAL');
        DB::table('pulse_values')->insert([
            ['timestamp' => 1_790_000_000, 'type' => 'system', 'key' => 'host-a', 'value' => '{}'],
            ['timestamp' => 1_790_000_000, 'type' => 'system', 'key' => 'host-b', 'value' => '{}'],
        ]);

        hashPulseKeysMigration()->up();

        expect(pulseKeyHashExpression('pulse_values'))->toContain('sha2')->not->toContain('md5')
            ->and(DB::table('pulse_values')->orderBy('key')->pluck('key_hash', 'key')->map(bin2hex(...))->all())
            ->toBe([
                'host-a' => substr(hash('sha256', 'host-a'), 0, 32),
                'host-b' => substr(hash('sha256', 'host-b'), 0, 32),
            ])
            ->and(fn () => DB::table('pulse_values')->insert(['timestamp' => 1, 'type' => 'system', 'key' => 'host-a', 'value' => '{}']))
            ->toThrow(UniqueConstraintViolationException::class);
    } finally {
        DB::table('pulse_values')->where('type', 'system')->delete();
    }
});

it('leaves sha2 tables alone and skips non-MySQL Pulse connections', function (): void {
    $connection = 'hash_pulse_keys_migration_test';
    config([
        "database.connections.{$connection}" => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
        'pulse.storage.database.connection' => $connection,
    ]);
    DB::purge($connection);
    Schema::connection($connection)->create('pulse_values', function (Blueprint $table): void {
        $table->id();
        $table->string('key_hash');
    });

    try {
        hashPulseKeysMigration()->up();

        expect(Schema::connection($connection)->getColumnListing('pulse_values'))->toBe(['id', 'key_hash']);
    } finally {
        config(['pulse.storage.database.connection' => null]);
        DB::purge($connection);
    }

    $before = array_map(pulseKeyHashExpression(...), pulseTables());
    hashPulseKeysMigration()->up();

    expect(array_map(pulseKeyHashExpression(...), pulseTables()))->toBe($before);
});
