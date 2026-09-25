<?php

declare(strict_types=1);

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

it('serializes worktree slot lifecycle races', function (): void {
    $backends = ['perl'];
    if (new ExecutableFinder()->find('flock') !== null) {
        $backends[] = 'flock';
    }

    $messages = [
        'PASS: two stale adopters serialize cleanup and claim separate slots',
        'PASS: prune and create serialize stale cleanup and ownership transfer',
        'PASS: interrupted reclaim retries and leaves live owners untouched',
        'PASS: prune reclaims old incomplete reservations and leaves recent ones alone',
        'PASS: allocation stops at the documented 84-slot limit',
        'PASS: remove holds the slot through Git removal and preserves safety refusals',
    ];

    foreach ($backends as $backend) {
        $process = new Process(
            [base_path('tests/scripts/worktree-races.sh'), $backend],
            base_path(),
            ['GIT_DIR' => false, 'GIT_WORK_TREE' => false],
        );
        $process->setTimeout(60);
        $process->run();

        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput());

        foreach ($messages as $message) {
            expect($process->getOutput())->toContain($message);
        }
        expect($process->getOutput())->toContain("PASS: lock backend {$backend}");
    }
})->skip(
    fn (): bool => new ExecutableFinder()->find('bash') === null,
    'The race harness needs bash, which the Alpine dev image does not ship; CI runs it.',
);
