<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

/**
 * A bare `throttle:N,M` keys its counter by user (or IP) alone, so every route
 * using one shares a single bucket whatever its own limit: a few plan views
 * once spent the allowance a test notification then needed.
 *
 * @return list<string>
 */
function bareThrottles(string $source): array
{
    $patterns = [
        "/(?<=['\"])throttle(?::[\\d.]+(?:,[\\d.]+)?)?(?=['\"])(?!['\"]\\s*=>)/",
        '/ThrottleRequests::with\([^,()]*(?:,[^,()]*)?\)/',
    ];

    $found = [];
    foreach ($patterns as $pattern) {
        preg_match_all($pattern, $source, $matches);
        array_push($found, ...$matches[0]);
    }

    return $found;
}

it('gives every numeric throttle a named bucket', function (): void {
    $files = collect([base_path('routes'), base_path('config'), app_path()])
        ->flatMap(fn (string $dir): array => File::allFiles($dir))
        ->map(fn (SplFileInfo $file): string => $file->getPathname())
        ->push(base_path('bootstrap/app.php'))
        ->filter(fn (string $path): bool => str_ends_with($path, '.php'));

    $bare = $files
        ->mapWithKeys(fn (string $path): array => [$path => bareThrottles((string) File::get($path))])
        ->filter()
        ->all();

    expect($bare)->toBe([], 'a throttle has no bucket name; add one, e.g. throttle:6,1,push-seen');
})->group('structure');

it('catches a bare throttle however it is written', function (string $source): void {
    expect(bareThrottles($source))->not->toBe([]);
})->with([
    'two arguments' => ["->middleware('throttle:60,1')"],
    'double quotes' => ['->middleware("throttle:60,1")'],
    'one argument' => ["->middleware('throttle:6')"],
    'no arguments' => ["->middleware('throttle')"],
    'fractional decay' => ["->middleware('throttle:6,0.5')"],
    'in a group' => ["Route::middleware(['throttle:60,1', 'devtools'])->group(fn () => null);"],
    'with() helper' => ['->middleware(ThrottleRequests::with(6, 1))'],
])->group('structure');

it('leaves named buckets, named limiters and config keys alone', function (string $source): void {
    expect(bareThrottles($source))->toBe([]);
})->with([
    'named bucket' => ["->middleware('throttle:6,1,push-seen')"],
    'named limiter' => ["->middleware('throttle:strava-sync')"],
    'with() helper and prefix' => ["->middleware(ThrottleRequests::with(6, 1, 'push-seen'))"],
    'password reset config key' => ["'throttle' => 60,"],
])->group('structure');
