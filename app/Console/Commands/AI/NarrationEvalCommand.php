<?php

declare(strict_types=1);

namespace App\Console\Commands\AI;

use App\Models\AI\TokenUsage;
use App\Models\User;
use App\Services\AI\LlmCostCalculator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

#[Signature('narration:eval {--kind=* : briefing_mascot_voice, run_insight or profile_voice; default all} {--max-calls= : Hard cap on narrator calls, required, at most 60}')]
#[Description('Run the narrators against seeded extremes on the demo athlete and check what the model writes back (manual, spends tokens)')]
class NarrationEvalCommand extends Command
{
    public const int MAX_CALLS_CEILING = 60;

    private const array CHECKS = ['validators', 'outcome_labels', 'raw_enum', 'markdown', 'numbers', 'direction'];

    public function handle(NarrationEvalFixtures $fixtureSet, LlmCostCalculator $costs): int
    {
        if (app()->isProduction()) {
            $this->error('narration:eval spends model tokens and refuses to run in production.');

            return self::FAILURE;
        }

        $cap = $this->cap();
        if ($cap === null) {
            return self::FAILURE;
        }

        $kinds = array_values(array_unique(array_map(strval(...), (array) $this->option('kind'))));
        $unknown = array_diff($kinds, NarrationEvalFixtures::KINDS);
        if ($unknown !== []) {
            $this->error('Unknown kind: '.implode(', ', $unknown).'. Choose from '.implode(', ', NarrationEvalFixtures::KINDS).'.');

            return self::FAILURE;
        }

        $demo = User::query()->where('is_demo', true)->first();
        if ($demo === null) {
            $this->error('No demo athlete found. Run demo:seed first.');

            return self::FAILURE;
        }

        $rows = [];
        $details = [];
        $calls = 0;
        $capped = 0;
        $failed = false;
        $spend = ['requests' => 0, 'input' => 0, 'output' => 0, 'cost' => 0.0];

        foreach ($fixtureSet->for($demo, $kinds) as $fixture) {
            if ($calls >= $cap) {
                $capped++;
                $rows[] = [$fixture->kind, $fixture->name, ...array_fill(0, count(self::CHECKS), '-'), 'skipped (cap)', '-', '-'];

                continue;
            }

            $outcome = $this->evaluate($fixture, $demo, $costs);
            if ($outcome === null) {
                $rows[] = [$fixture->kind, $fixture->name, ...array_fill(0, count(self::CHECKS), '-'), 'skipped (no history)', '-', '-'];

                continue;
            }

            $calls += $outcome['called'] ? 1 : 0;
            $failed = $failed || $outcome['failures'] !== [];
            $spend['requests'] += $outcome['spend']['requests'];
            $spend['input'] += $outcome['spend']['input'];
            $spend['output'] += $outcome['spend']['output'];
            $spend['cost'] += $outcome['spend']['cost'];

            $rows[] = [
                $fixture->kind,
                $fixture->name,
                ...array_map(static fn (string $check): string => isset($outcome['failures'][$check]) ? 'FAIL' : 'ok', self::CHECKS),
                $outcome['failures'] === [] ? 'PASS' : 'FAIL',
                $outcome['spend']['input'] + $outcome['spend']['output'],
                sprintf('$%.4f', $outcome['spend']['cost']),
            ];

            if ($outcome['failures'] !== []) {
                $details[] = [$fixture, $outcome];
            }
        }

        $this->table(['kind', 'fixture', ...self::CHECKS, 'result', 'tokens', 'cost'], $rows);

        foreach ($details as [$fixture, $outcome]) {
            $this->line("FAIL {$fixture->kind}/{$fixture->name}");
            foreach ($outcome['failures'] as $check => $reason) {
                $this->line("  {$check}: {$reason}");
            }
            $this->line('  text: '.$outcome['text']);
        }

        if ($capped > 0) {
            $this->warn("Stopped at the cap of {$cap} calls; {$capped} fixtures were not run.");
        }

        $this->line(sprintf(
            'Spend: %d calls, %d model requests, %d input + %d output tokens, $%.4f',
            $calls,
            $spend['requests'],
            $spend['input'],
            $spend['output'],
            $spend['cost'],
        ));

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function cap(): ?int
    {
        $option = $this->option('max-calls');

        if ($option === null || ! ctype_digit((string) $option) || (int) $option < 1) {
            $this->error('Pass an explicit --max-calls=N (1 to '.self::MAX_CALLS_CEILING.'); there is no default.');

            return null;
        }

        if ((int) $option > self::MAX_CALLS_CEILING) {
            $this->error('--max-calls is capped at '.self::MAX_CALLS_CEILING.'.');

            return null;
        }

        return (int) $option;
    }

    /**
     * @return array{called: bool, failures: array<string, string>, text: string, spend: array{requests: int, input: int, output: int, cost: float}}|null
     */
    private function evaluate(NarrationEvalFixture $fixture, User $demo, LlmCostCalculator $costs): ?array
    {
        $lastUsageId = (int) TokenUsage::query()->max('id');
        $failures = [];
        $text = '';
        $called = false;

        DB::beginTransaction();

        try {
            $case = ($fixture->build)();
            if ($case === null) {
                return null;
            }

            try {
                $called = true;
                $text = ($case['generate'])();
                $failures = array_filter(NarrationEvalChecks::run($text, $case['evidence'], $case['direction']));
            } catch (Throwable $e) {
                $failures = ['validators' => $e->getMessage()];
            }
        } catch (Throwable $e) {
            $failures = ['validators' => 'fixture could not be built: '.$e->getMessage()];
        } finally {
            DB::rollBack();
        }

        return ['called' => $called, 'failures' => $failures, 'text' => $text, 'spend' => $this->spendSince($lastUsageId, $demo, $costs)];
    }

    /**
     * @return array{requests: int, input: int, output: int, cost: float}
     */
    private function spendSince(int $lastUsageId, User $demo, LlmCostCalculator $costs): array
    {
        $spend = ['requests' => 0, 'input' => 0, 'output' => 0, 'cost' => 0.0];

        $usages = TokenUsage::query()->where('id', '>', $lastUsageId)->where('user_id', $demo->id)->get();
        foreach ($usages as $usage) {
            $spend['requests'] += $usage->steps;
            $spend['input'] += $usage->prompt_tokens;
            $spend['output'] += $usage->completion_tokens;
            $spend['cost'] += $costs->costFor((string) $usage->model, $usage->prompt_tokens, $usage->completion_tokens, $usage->cached_tokens);
        }

        return $spend;
    }
}
