<?php

declare(strict_types=1);

use App\Services\Docs\DocStalenessChecker;
use Carbon\CarbonImmutable;

function staleCommandFinding(): array
{
    return [[
        'doc' => 'architecture/foo.md',
        'reviewed' => CarbonImmutable::parse('2026-01-01'),
        'staleRefs' => [['path' => 'app/Foo.php', 'committedAt' => CarbonImmutable::parse('2026-02-02')]],
    ]];
}

it('reports that every note is fresh when nothing is stale', function (): void {
    $this->mock(DocStalenessChecker::class)
        ->shouldReceive('findStale')
        ->once()
        ->andReturn([]);

    $this->artisan('docs:stale')
        ->expectsOutputToContain('every note is fresh')
        ->assertExitCode(0);
});

it('lists each stale note and the code_ref that moved', function (): void {
    $this->mock(DocStalenessChecker::class)
        ->shouldReceive('findStale')
        ->once()
        ->andReturn(staleCommandFinding());

    $this->artisan('docs:stale')
        ->expectsOutputToContain('architecture/foo.md')
        ->expectsOutputToContain('app/Foo.php')
        ->assertExitCode(0);
});

it('exits non-zero under --strict when a note is stale', function (): void {
    $this->mock(DocStalenessChecker::class)
        ->shouldReceive('findStale')
        ->once()
        ->andReturn(staleCommandFinding());

    $this->artisan('docs:stale --strict')->assertExitCode(1);
});
