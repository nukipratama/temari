<?php

declare(strict_types=1);

namespace App\Services\Run\Plan;

use App\Enums\RaceAmbitionState;
use App\Enums\RaceSupport;
use App\Enums\PrCategory;
use App\Models\RaceGoal;
use App\Models\User;
use App\Services\Run\Metrics\VdotEstimator;
use Illuminate\Support\Carbon;

final readonly class RaceAmbitionAssessor
{
    public const float ON_TRACK_WITHIN = 0.03;

    public const float AMBITIOUS_WITHIN = 0.06;

    public function __construct(private VdotEstimator $vdotEstimator)
    {
    }

    public function assess(User $user, RaceGoal $race, ?Carbon $asOf = null): RaceAmbition
    {
        $distanceKm = $race->distance_m / 1000;
        $targetPace = (int) round($race->goal_time_sec / $distanceKm);
        $estimate = $this->vdotEstimator->estimate($user, $asOf);
        $supportedSec = $estimate !== null && RaceSupport::forDistance((float) $race->distance_m)->dedicatedPreparation()
            ? $this->vdotEstimator->raceTimeForVdot($estimate['vdot'], (float) $race->distance_m)
            : null;

        if ($estimate === null || $supportedSec === null) {
            return new RaceAmbition(RaceAmbitionState::Unknown, $race->goal_time_sec, $targetPace, null, null, null, null);
        }

        $supportedSec = (int) round($supportedSec);
        $gap = 1 - $race->goal_time_sec / $supportedSec;
        $evidenceM = $estimate['longest_source_m'] ?? $estimate['distance_m'] ?? PrCategory::tryFrom($estimate['source_category'])?->distanceMeters();
        $basisM = $estimate['distance_m'] ?? null;

        return new RaceAmbition(
            match (true) {
                $evidenceM === null || $evidenceM < $race->distance_m / 2 => RaceAmbitionState::LowEvidence,
                $gap <= self::ON_TRACK_WITHIN => RaceAmbitionState::OnTrack,
                $gap <= self::AMBITIOUS_WITHIN => RaceAmbitionState::Ambitious,
                default => RaceAmbitionState::Unsupported,
            },
            $race->goal_time_sec,
            $targetPace,
            $supportedSec,
            (int) round($supportedSec / $distanceKm),
            round($gap * 100, 1),
            $estimate['confidence'],
            $basisM === null ? null : ['distance_m' => $basisM, 'performed_on' => $estimate['set_at']->toDateString(), 'activity_id' => $estimate['source_activity_id'] ?? null],
        );
    }
}
