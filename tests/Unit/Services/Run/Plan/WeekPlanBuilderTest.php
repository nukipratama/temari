<?php

declare(strict_types=1);

use App\Enums\DistanceBand;
use App\Enums\PaceBand;
use App\Enums\PlanPhase;
use App\Enums\SessionType;
use App\Services\Run\Plan\WeekPlanBuilder;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    $this->builder = new WeekPlanBuilder();
    $this->monday = Carbon::parse('2026-08-10')->startOfWeek(Carbon::MONDAY);
});

it('produces exactly one row per day, minus pinned and past-in-current-week dates', function (): void {
    $rows = $this->builder->build($this->monday, PlanPhase::Base, 4, [], null, false);

    expect($rows)->toHaveCount(7);
});

it('never assigns a row to a pinned date', function (): void {
    $pinned = $this->monday->copy()->addDays(1)->toDateString();

    $rows = $this->builder->build($this->monday, PlanPhase::Base, 4, [$pinned => true], null, false);

    expect($rows)->not->toHaveKey($pinned)
        ->and($rows)->toHaveCount(6);
});

it('never assigns a row to a date before notBefore', function (): void {
    $notBefore = $this->monday->copy()->addDays(3);

    $rows = $this->builder->build($this->monday, PlanPhase::Base, 4, [], null, false, $notBefore);

    foreach (array_keys($rows) as $date) {
        expect(Carbon::parse($date)->lt($notBefore))->toBeFalse();
    }
    expect($rows)->toHaveCount(4); // Thu..Sun
});

it('gives the last training day of the week the Long session type and band', function (): void {
    $rows = $this->builder->build($this->monday, PlanPhase::Build, 4, [], null, false);
    // 4-session template: Tue, Thu, Sat, Sun -- Sun is the long day.
    $sunday = $this->monday->copy()->addDays(6)->toDateString();

    expect($rows[$sunday]['session_type'])->toBe(SessionType::Long)
        ->and($rows[$sunday]['distance_band'])->toBe(DistanceBand::Long)
        ->and($rows[$sunday]['pace_band'])->toBe(PaceBand::Easy);
});

it('marks every non-training day as Rest with a null pace band', function (): void {
    $rows = $this->builder->build($this->monday, PlanPhase::Build, 3, [], null, false);
    // 3-session template: Tue, Thu, Sat. Monday is a rest day.
    $monday = $this->monday->toDateString();

    expect($rows[$monday]['session_type'])->toBe(SessionType::Rest)
        ->and($rows[$monday]['distance_band'])->toBe(DistanceBand::Rest)
        ->and($rows[$monday]['pace_band'])->toBeNull();
});

it('Base phase stays quality-free below 4 sessions/week, adds one Tempo at 4+', function (): void {
    $withoutQuality = $this->builder->build($this->monday, PlanPhase::Base, 3, [], null, false);
    $withQuality = $this->builder->build($this->monday, PlanPhase::Base, 4, [], null, false);

    expect(collect($withoutQuality)->pluck('session_type'))->not->toContain(SessionType::Tempo);
    expect(collect($withQuality)->pluck('session_type'))->toContain(SessionType::Tempo);
});

it('Build phase mixes Tempo and Interval once sessions/week exceeds 4, race-oriented', function (): void {
    $rows = $this->builder->build($this->monday, PlanPhase::Build, 6, [], 42_195.0, false);
    $types = collect($rows)->pluck('session_type');

    expect($types)->toContain(SessionType::Tempo)
        ->and($types)->toContain(SessionType::Interval);
});

it('self-scaled Build stays threshold-only, never adds Interval, even at 2 quality slots', function (): void {
    $rows = $this->builder->build($this->monday, PlanPhase::Build, 6, [], null, true);
    $types = collect($rows)->pluck('session_type');
    $paceBands = collect($rows)->pluck('pace_band')->filter();

    expect($types)->not->toContain(SessionType::Interval)
        ->and($paceBands)->not->toContain(PaceBand::Interval);
});

it('Peak/Taper for a marathon-distance race narrows to one race-pace-specific session', function (): void {
    $rows = $this->builder->build($this->monday, PlanPhase::Peak, 6, [], 42_195.0, false);
    $marathonPaceCount = collect($rows)->filter(fn (array $r): bool => $r['pace_band'] === PaceBand::Marathon)->count();

    // One marathon-paced quality session, plus the long run also runs at marathon pace.
    expect($marathonPaceCount)->toBe(2);
});

it('Peak/Taper for a shorter race keeps the threshold/interval mix, not marathon pace', function (): void {
    $rows = $this->builder->build($this->monday, PlanPhase::Peak, 6, [], 10_000.0, false);
    $sunday = $this->monday->copy()->addDays(6)->toDateString();

    expect(collect($rows)->pluck('pace_band'))->not->toContain(PaceBand::Marathon)
        ->and($rows[$sunday]['pace_band'])->toBe(PaceBand::Easy);
});

it('Deload carries no quality sessions at all', function (): void {
    $rows = $this->builder->build($this->monday, PlanPhase::Deload, 6, [], null, true);
    $types = collect($rows)->pluck('session_type')->unique()->values()->all();

    expect($types)->not->toContain(SessionType::Tempo)
        ->and($types)->not->toContain(SessionType::Interval);
});

it('assigns the first Easy day Medium band and the rest Short', function (): void {
    // 6-session template, Deload (0 quality slots): Mon,Tue,Wed,Thu,Sat,Sun train,
    // Sun is Long, the other 5 are Easy in date order.
    $rows = $this->builder->build($this->monday, PlanPhase::Deload, 6, [], null, true);
    $easyDates = collect($rows)
        ->filter(fn (array $r): bool => $r['session_type'] === SessionType::Easy)
        ->keys()
        ->sort()
        ->values();

    $bands = $easyDates->map(fn (string $date) => $rows[$date]['distance_band']);
    expect($bands->first())->toBe(DistanceBand::Medium);
    expect($bands->slice(1)->unique()->values()->all())->toBe([DistanceBand::Short]);
});

it('clamps an out-of-range session count into the supported 3-6 template range', function (): void {
    $rows = $this->builder->build($this->monday, PlanPhase::Base, 10, [], null, false);

    expect($rows)->toHaveCount(7); // still one row per day; falls back to the 6-session template
});

it('tags every produced row with the phase it was built for', function (): void {
    $rows = $this->builder->build($this->monday, PlanPhase::Peak, 4, [], null, false);

    foreach ($rows as $row) {
        expect($row['phase'])->toBe(PlanPhase::Peak);
    }
});

function qualityCount(array $rows): int
{
    return collect($rows)
        ->filter(fn (array $r): bool => in_array($r['session_type'], [SessionType::Tempo, SessionType::Interval], true))
        ->count();
}

it('adds a quality session when race-pace feedback asks for more', function (): void {
    $before = $this->builder->build($this->monday, PlanPhase::Build, 6, [], null, true);
    $after = $this->builder->build($this->monday, PlanPhase::Build, 6, [], null, true, null, 1);

    expect(qualityCount($before))->toBe(2)
        ->and(qualityCount($after))->toBe(3);
});

it('drops a quality session when race-pace feedback asks for less', function (): void {
    $before = $this->builder->build($this->monday, PlanPhase::Build, 6, [], null, true);
    $after = $this->builder->build($this->monday, PlanPhase::Build, 6, [], null, true, null, -1);

    expect(qualityCount($before))->toBe(2)
        ->and(qualityCount($after))->toBe(1);
});

it('drops the week to zero quality when asked for less than it already carries', function (): void {
    $rows = $this->builder->build($this->monday, PlanPhase::Base, 4, [], null, false, null, -1);

    expect(qualityCount($rows))->toBe(0);
});

it('refuses to add a quality session to a week too short to absorb it', function (): void {
    $rows = $this->builder->build($this->monday, PlanPhase::Build, 4, [], null, true, null, 1);

    expect(qualityCount($rows))->toBe(1);
});

it('never lets race-pace feedback add quality work to a taper or a deload', function (): void {
    $taper = $this->builder->build($this->monday, PlanPhase::Taper, 6, [], 10_000.0, false, null, 1);
    $deload = $this->builder->build($this->monday, PlanPhase::Deload, 6, [], null, true, null, 1);

    expect(qualityCount($taper))->toBe(2)
        ->and(qualityCount($deload))->toBe(0);
});

it('caps the quality block even when feedback keeps asking for more', function (): void {
    $rows = $this->builder->build($this->monday, PlanPhase::Build, 6, [], null, true, null, 5);

    expect(qualityCount($rows))->toBe(3);
});

it('leaves the season-goal slot count on the unadapted phase baseline', function (): void {
    expect($this->builder->qualitySlotCount(PlanPhase::Build, 6, null, true))->toBe(2);
});
