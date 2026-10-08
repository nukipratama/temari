<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

it('bans doesntExpectOutputToContain under tests', function (): void {
    $offenders = collect(File::allFiles(base_path('tests')))
        ->filter(fn ($file): bool => $file->getExtension() === 'php' && $file->getRealPath() !== __FILE__)
        ->filter(fn ($file): bool => str_contains(File::get($file->getRealPath()), 'doesntExpectOutputToContain'))
        ->map(fn ($file): string => $file->getRelativePathname())
        ->values();

    expect($offenders->all())->toBe(
        [],
        "doesntExpectOutputToContain can pass while the text is printed. Use Artisan::call() and expect(Artisan::output())->not->toContain() in:\n  ".$offenders->implode("\n  "),
    );
})->group('structure');
