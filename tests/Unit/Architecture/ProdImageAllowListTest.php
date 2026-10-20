<?php

declare(strict_types=1);

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;

/** @return Collection<int, non-empty-string> */
function dockerignorePatterns(): Collection
{
    $lines = file(base_path('.dockerignore'), FILE_IGNORE_NEW_LINES);

    return collect($lines === false ? throw new RuntimeException('.dockerignore is unreadable.') : $lines)
        ->map(fn (string $line): string => trim($line))
        ->reject(fn (string $line): bool => $line === '' || str_starts_with($line, '#'))
        ->values();
}

/** @return Collection<int, string> */
function allowListedPaths(): Collection
{
    return dockerignorePatterns()
        ->filter(fn (string $pattern): bool => str_starts_with($pattern, '!'))
        ->map(fn (string $pattern): string => rtrim(substr($pattern, 1), '/'))
        ->values();
}

/** @return list<string> */
function expectedImageEntries(): array
{
    $step = collect(arrayOf(data_get(Yaml::parseFile(base_path('.github/workflows/ci.yml')), 'jobs.build.steps')))
        ->firstWhere('name', 'Assert the shipped app entries');

    expect($step)->not->toBeNull('build-prod-image lost its image-contents assertion step.');

    $entries = data_get($step, 'env.EXPECTED_ENTRIES');

    return preg_split('/\s+/', trim(is_string($entries) ? $entries : throw new InvalidArgumentException('EXPECTED_ENTRIES is not a string.'))) ?: throw new RuntimeException('EXPECTED_ENTRIES could not be split.');
}

it('excludes everything the allow-list does not name', function (): void {
    expect(dockerignorePatterns()->first())->toBe('*');
})->group('structure');

it('asserts in CI exactly the top-level entries the allow-list ships', function (): void {
    $shipped = allowListedPaths()
        ->map(fn (string $path): string => explode('/', $path)[0])
        ->reject(fn (string $entry): bool => $entry === '.npmrc')
        ->push('vendor')
        ->unique()
        ->sort()
        ->values()
        ->all();

    $expected = collect(expectedImageEntries())->sort()->values()->all();

    expect($expected)->toBe($shipped);
})->group('structure');

it('keeps every file the Dockerfile copies from the build context in the allow-list', function (): void {
    preg_match_all('/^COPY\s+(?!--from)(.+)$/m', (string) file_get_contents(base_path('Dockerfile')), $matches);

    $sources = collect($matches[1])
        ->flatMap(fn (string $args): array => array_slice(preg_split('/\s+/', trim($args)) ?: throw new RuntimeException('COPY arguments could not be split.'), 0, -1))
        ->reject(fn (string $source): bool => $source === '.' || str_starts_with($source, '--'))
        ->unique()
        ->values();

    $allowed = allowListedPaths();

    $missing = $sources->reject(fn (string $source): bool => $allowed->contains(
        fn (string $path): bool => $source === $path || str_starts_with($source, $path.'/'),
    ));

    expect($sources)->not->toBeEmpty()
        ->and($missing->values()->all())->toBe([]);
})->group('structure');

it('installs the assets stage under the repo .npmrc, so dependency install scripts stay off', function (): void {
    $assetsStage = Str::between((string) file_get_contents(base_path('Dockerfile')), 'AS assets', 'npm ci');

    expect($assetsStage)->toMatch('/^COPY\s+(?!--from)[^\n]*\.npmrc\s/m');
})->group('structure');

it('loads and lists the image only when an image input changed, never on a main push', function (): void {
    $workflow = Yaml::parseFile(base_path('.github/workflows/ci.yml'));
    $steps = collect(arrayOf(data_get($workflow, 'jobs.build.steps')))->keyBy('name');

    expect(data_get($steps['Load the runtime image locally'], 'if'))->toBe("needs.changes.outputs.image == 'true'")
        ->and(data_get($steps['Assert the shipped app entries'], 'if'))->toBe("needs.changes.outputs.image == 'true'")
        ->and(data_get($workflow, 'jobs.changes.outputs.image'))->toBe('${{ steps.filter.outputs.image }}')
        ->and(data_get($workflow, 'jobs.changes.if'))->toBe("\${{ github.event_name == 'pull_request' }}");
})->group('structure');
