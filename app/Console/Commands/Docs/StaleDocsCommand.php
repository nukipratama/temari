<?php

declare(strict_types=1);

namespace App\Console\Commands\Docs;

use App\Services\Docs\DocStalenessChecker;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

#[Signature('docs:stale {--strict : Exit non-zero (1) when any note is stale}')]
#[Description('Report docs/ notes whose cited code changed after the note was last reviewed.')]
class StaleDocsCommand extends Command
{
    public function handle(DocStalenessChecker $checker): int
    {
        $findings = $checker->findStale(base_path('docs'), $this->lastCommittedAt(...));

        if ($findings === []) {
            $this->info('docs:stale — every note is fresh (no cited code changed since its reviewed date).');

            return self::SUCCESS;
        }

        $this->warn(sprintf('docs:stale — %d note(s) may be stale (cited code changed after the reviewed date):', count($findings)));
        $this->newLine();

        foreach ($findings as $finding) {
            $this->line(sprintf('  <fg=yellow>%s</> (reviewed %s)', $finding['doc'], $finding['reviewed']->toDateString()));

            foreach ($finding['staleRefs'] as $ref) {
                $this->line(sprintf('    - %s changed %s', $ref['path'], $ref['committedAt']->toDateString()));
            }
        }

        $this->newLine();
        $this->line('Re-read each note against its code, fix any drift, then bump the `reviewed:` date.');

        return $this->option('strict') ? self::FAILURE : self::SUCCESS;
    }

    private function lastCommittedAt(string $path): ?CarbonImmutable
    {
        $process = new Process(['git', 'log', '-1', '--format=%cI', '--', $path], base_path());
        $process->run();

        if (! $process->isSuccessful()) {
            return null;
        }

        $iso = trim($process->getOutput());

        return $iso === '' ? null : CarbonImmutable::parse($iso);
    }
}
