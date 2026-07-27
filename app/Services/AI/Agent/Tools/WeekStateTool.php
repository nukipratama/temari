<?php

declare(strict_types=1);

namespace App\Services\AI\Agent\Tools;

use App\Models\User;
use App\Services\Run\Metrics\TrainingLoad;
use App\Services\Run\Story\BriefingContext;
use Illuminate\Support\Carbon;

/**
 * The dashboard briefing's whole picture of the week in one read.
 *
 * These fields are produced together by {@see BriefingContext::forUser()} and
 * cost the same query work whether one or all fifteen are wanted, so splitting
 * them across several tools would only buy extra round trips.
 */
final class WeekStateTool extends UserTool
{
    public function __construct(
        User $user,
        Carbon $asOf,
        private readonly TrainingLoad $trainingLoad,
    ) {
        parent::__construct($user, $asOf);
    }

    public function name(): string
    {
        return 'get_week_state';
    }

    public function description(): string
    {
        return 'Keadaan minggu ini: lari dan km minggu ini vs minggu lalu, volume_ramp_pct, '
            .'berapa minggu beruntun aktif, arah kebugaran, jam berapa sekarang (time_bucket), '
            .'sudah lari hari ini atau belum, sudah berapa jam sejak lari terakhir, form_status, '
            .'plus readiness_ceiling dan build_nudge yang membatasi seberapa keras kamu boleh '
            .'menyarankan. Panggil sebelum menyarankan apa pun.';
    }

    /** @return array<string, mixed> */
    public function handle(array $arguments): array
    {
        $load = $this->trainingLoad->summary($this->user, $this->asOf) ?? [];

        return BriefingContext::forUser($this->user, $this->asOf, $load)->toArray();
    }
}
