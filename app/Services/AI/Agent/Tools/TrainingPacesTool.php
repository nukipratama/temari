<?php

declare(strict_types=1);

namespace App\Services\AI\Agent\Tools;

use App\Models\User;
use App\Services\Run\Metrics\PaceFormatter;
use App\Services\Run\Metrics\TrainingPaceCalculator;
use App\Services\Run\Metrics\VdotEstimator;
use Illuminate\Support\Carbon;

final class TrainingPacesTool extends UserTool
{
    public function __construct(
        User $user,
        Carbon $asOf,
        private readonly VdotEstimator $vdotEstimator,
        private readonly TrainingPaceCalculator $paceCalculator,
    ) {
        parent::__construct($user, $asOf);
    }

    public function name(): string
    {
        return 'get_training_paces';
    }

    public function description(): string
    {
        return "The user's training paces derived from their VDOT: easy, marathon, threshold, "
            .'interval. Each comes as a _formatted mm:ss/km (the only form to quote -- matches what '
            .'the app shows) and a _sec raw seconds-per-km twin, for judging size, never for '
            .'quoting. Call this when you want to suggest a concrete pace. If the paces don\'t show '
            .'up at all, their PR history isn\'t enough yet to estimate VDOT.';
    }

    /** @return array<string, mixed> */
    public function handle(array $arguments): array
    {
        $paces = $this->paceCalculator->fromVdotResult($this->vdotEstimator->estimate($this->user, $this->asOf));

        return array_merge(
            ...array_map(
                fn (string $key): array => self::pacePair($key, $paces[$key] ?? null),
                ['easy', 'marathon', 'threshold', 'interval'],
            ),
        );
    }

    /** @return array<string, mixed> */
    private static function pacePair(string $key, ?int $sec): array
    {
        return [
            "{$key}_pace_sec" => $sec,
            "{$key}_pace_formatted" => $sec === null ? null : PaceFormatter::format((float) $sec),
        ];
    }
}
