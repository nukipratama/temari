<?php

declare(strict_types=1);

namespace App\Services\AI\Agent\Tools;

use App\Actions\Run\Metrics\ResolveRunBaselineAction;
use App\Models\User;
use Illuminate\Support\Carbon;

final class RecentBaselineTool extends UserTool
{
    public function __construct(
        User $user,
        Carbon $asOf,
        private readonly ResolveRunBaselineAction $baseline,
        /** Excluded from its own baseline when the caller is narrating that run. */
        private readonly ?int $excludeActivityId = null,
    ) {
        parent::__construct($user, $asOf);
    }

    public function name(): string
    {
        return 'get_recent_baseline';
    }

    public function description(): string
    {
        return 'Rata-rata 28 hari terakhir milik pengguna (pace, HR, decoupling). Panggil kalau mau '
            .'bilang sesuatu lebih cepat/lambat/berat dari biasanya. Kalau recent_baseline_28d gak '
            .'muncul, riwayatnya masih tipis.';
    }

    /** @return array<string, mixed> */
    public function handle(array $arguments): array
    {
        return [
            'recent_baseline_28d' => ($this->baseline)(
                $this->user->id,
                $this->asOf,
                $this->excludeActivityId,
            ),
        ];
    }
}
