<?php

declare(strict_types=1);

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\SplFileInfo;
use Symfony\Component\Yaml\Yaml;

/** @return Collection<string, array<string, mixed>> */
function workflowJobs(): Collection
{
    return collect(File::files(base_path('.github/workflows')))
        ->filter(fn (SplFileInfo $file): bool => $file->getExtension() === 'yml')
        ->flatMap(fn (SplFileInfo $file): array => collect(Yaml::parseFile($file->getPathname())['jobs'])
            ->mapWithKeys(fn (array $job, string $id): array => ["{$file->getFilename()}: {$id}" => $job])
            ->all());
}

it('gives every job that runs on a runner a timeout-minutes', function (): void {
    $missing = workflowJobs()
        ->reject(fn (array $job): bool => isset($job['uses']))
        ->reject(fn (array $job): bool => is_int($job['timeout-minutes'] ?? null))
        ->keys()
        ->all();

    expect(workflowJobs())->not->toBeEmpty()
        ->and($missing)->toBe([], "These jobs can hang until GitHub's 6-hour default.");
})->group('structure');

it('calls only reusable workflows whose own jobs carry the timeout', function (): void {
    $external = workflowJobs()
        ->pluck('uses')
        ->filter()
        ->reject(fn (string $uses): bool => str_starts_with($uses, './.github/workflows/'))
        ->values()
        ->all();

    expect($external)->toBe([]);
})->group('structure');
