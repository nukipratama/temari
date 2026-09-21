<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

function migrationGuard(array $files): Process
{
    $process = new Process([
        PHP_BINARY,
        base_path('scripts/check-migration-safety.php'),
        '--files',
        ...$files,
    ]);
    $process->run();

    return $process;
}

it('runs the migration guard from the unconditional repository checks with full git history', function (): void {
    $job = Yaml::parseFile(base_path('.github/workflows/ci.yml'))['jobs']['repo-guards'];
    $checkout = $job['steps'][0];
    $guard = collect($job['steps'])->firstWhere('name', 'Reject unmarked destructive migrations');

    expect($checkout['with']['fetch-depth'])->toBe(0)
        ->and($guard['run'])->toContain('scripts/check-migration-safety.php --base');
});

it('rejects the historical destructive migration patterns that motivated the guard', function (string $file): void {
    $process = migrationGuard([base_path($file)]);

    expect($process->isSuccessful())->toBeFalse()
        ->and($process->getErrorOutput())->toContain('destructive migration operation');
})->with([
    'drop a column in the same release' => 'database/migrations/2026_09_18_010000_drop_share_image_path_from_run_cards_table.php',
    'delete rows and drop their table together' => 'database/migrations/2026_09_09_120000_drop_user_unlocks_table.php',
    'rename a live column in place' => 'database/migrations/2026_09_16_000000_rename_moving_time_sec_on_weekly_snapshots_table.php',
]);

it('allows additive migrations without a marker', function (): void {
    $process = migrationGuard([
        base_path('database/migrations/2026_09_19_120000_add_narrated_early_at_to_ai_analyses_table.php'),
    ]);

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
});

it('allows an explicitly marked contract migration', function (): void {
    $path = sys_get_temp_dir().'/contract-migration-'.uniqid().'.php';
    file_put_contents($path, <<<'PHP'
<?php

return new class extends Migration
{
    /** @contract-migration */
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('retired_column'));
    }
};
PHP);

    try {
        $process = migrationGuard([$path]);
        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
    } finally {
        unlink($path);
    }
});

it('ignores destructive rollback operations in down', function (): void {
    $path = sys_get_temp_dir().'/additive-migration-'.uniqid().'.php';
    file_put_contents($path, <<<'PHP'
<?php

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->string('nickname')->nullable());
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('nickname'));
    }
};
PHP);

    try {
        $process = migrationGuard([$path]);
        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
    } finally {
        unlink($path);
    }
});
