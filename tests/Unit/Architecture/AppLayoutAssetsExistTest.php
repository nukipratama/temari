<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

/**
 * `og-default.png` was referenced by the layout for months while never existing
 * on disk, so every link preview of the app rendered without an image and
 * nothing failed. These assertions close that gap for the whole layout.
 */
function appLayoutBlade(): string
{
    return (string) File::get(resource_path('views/app.blade.php'));
}

/** @return list<string> */
function appLayoutLiteralAssets(string $blade): array
{
    preg_match_all("/asset\('([^']+)'\)/", $blade, $matches);

    return $matches[1];
}

/** @return list<string> */
function appLayoutSplashAssets(string $blade): array
{
    preg_match_all("/\['w' => (\d+), 'h' => (\d+), 'dpr' => (\d+)\]/", $blade, $devices, PREG_SET_ORDER);

    $paths = [];
    foreach ($devices as $device) {
        foreach (['dark', 'light'] as $ground) {
            $paths[] = sprintf(
                'splash/splash-%s-%dx%d.png',
                $ground,
                (int) $device[1] * (int) $device[3],
                (int) $device[2] * (int) $device[3],
            );
        }
    }

    return $paths;
}

it('resolves every asset() call the layout makes', function (): void {
    $blade = appLayoutBlade();
    $splash = appLayoutSplashAssets($blade);

    $resolved = count(appLayoutLiteralAssets($blade)) + ($splash === [] ? 0 : 1);

    expect($resolved)->toBe(
        preg_match_all('/asset\(/', $blade),
        'An asset() call in app.blade.php is not covered by this test. Teach it the new '
        .'reference rather than letting the file drop out of the existence sweep.',
    );
})->group('structure');

it('points every layout asset at a file that exists', function (): void {
    $blade = appLayoutBlade();
    $referenced = [...appLayoutLiteralAssets($blade), ...appLayoutSplashAssets($blade)];

    expect($referenced)->not->toBeEmpty();

    $missing = array_values(array_filter(
        $referenced,
        fn (string $path): bool => ! File::exists(public_path($path)),
    ));

    expect($missing)->toBe([], 'Referenced from app.blade.php but absent from public/: '.implode(', ', $missing));
})->group('structure');
