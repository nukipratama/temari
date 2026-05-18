<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AI\Analysis;
use App\Models\PersonalRecord;
use App\Models\User;
use App\Services\AI\AnalysisType;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RekorController extends Controller
{
    public function __invoke(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $personalRecords = PersonalRecord::query()
            ->where('user_id', $user->id)
            ->orderBy('category')
            ->with(['activity:id', 'activity.detail:id,activity_id,name'])
            ->get();

        $analyses = Analysis::query()
            ->where('subject_type', PersonalRecord::class)
            ->where('analysis_type', AnalysisType::PrContext)
            ->whereIn('subject_id', $personalRecords->pluck('id'))
            ->get()
            ->keyBy('subject_id');

        $payload = $personalRecords->map(fn (PersonalRecord $row): array => array_merge($row->toArray(), [
            'context_analysis' => Analysis::toPayload(
                $analyses->get($row->id),
                AnalysisType::PrContext,
                PersonalRecord::class,
                $row->id,
            ),
        ]))->all();

        return Inertia::render('Rekor', [
            'personalRecords' => $payload,
        ]);
    }
}
