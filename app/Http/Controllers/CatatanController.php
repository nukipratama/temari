<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AI\Analysis;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\AI\AnalysisType;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CatatanController extends Controller
{
    public function __invoke(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $snapshots = WeeklySnapshot::query()
            ->where('user_id', $user->id)
            ->orderByDesc('week_ending')
            ->limit(26)
            ->get();

        $analyses = Analysis::query()
            ->where('subject_type', WeeklySnapshot::class)
            ->where('analysis_type', AnalysisType::WeeklyRecap)
            ->whereIn('subject_id', $snapshots->pluck('id'))
            ->get()
            ->keyBy('subject_id');

        $payload = $snapshots->map(fn (WeeklySnapshot $row): array => array_merge($row->toArray(), [
            'recap_analysis' => Analysis::toPayload(
                $analyses->get($row->id),
                AnalysisType::WeeklyRecap,
                WeeklySnapshot::class,
                $row->id,
            ),
        ]))->all();

        return Inertia::render('Catatan', [
            'snapshots' => $payload,
        ]);
    }
}
