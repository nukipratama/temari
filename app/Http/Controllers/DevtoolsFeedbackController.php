<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\FeedbackSubject;
use App\Models\AI\Analysis;
use App\Models\Feedback;
use App\Models\User;
use App\Services\Telegram\AnalysisMessagePresenter;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Lists the flags runners have filed via "flag this as wrong", newest first.
 * Read-only: there is no admin action here, see docs/features/feedback.md.
 */
class DevtoolsFeedbackController extends Controller
{
    private const int MAX_ROWS = 200;

    public function __invoke(AnalysisMessagePresenter $presenter): Response
    {
        $feedback = Feedback::query()
            ->with('user:id,name')
            ->latest('id')
            ->limit(self::MAX_ROWS)
            ->get();

        $narrationIds = $feedback
            ->where('subject_type', FeedbackSubject::Narration)
            ->pluck('subject_id');

        $analyses = Analysis::query()->whereIn('id', $narrationIds)->get()->keyBy('id');

        return Inertia::render('DevtoolsFeedback', [
            'rows' => $feedback
                ->map(fn (Feedback $row): array => $this->rowPayload($row, $analyses, $presenter))
                ->values()
                ->all(),
        ]);
    }

    /**
     * @param  Collection<int, Analysis>  $analyses
     * @return array{id:int, created_at:string, created_at_full:string, runner:string, subject_label:string, subject_url:string|null, reason:string|null, note:string|null}
     */
    private function rowPayload(Feedback $row, Collection $analyses, AnalysisMessagePresenter $presenter): array
    {
        [$label, $url] = $row->subject_type === FeedbackSubject::PlanDay
            ? ['plan day', route('plan')]
            : $this->narrationSubject($row->subject_id, $analyses, $presenter);

        $createdAt = $row->created_at ?? now();
        $user = $row->user;

        return [
            'id' => $row->id,
            'created_at' => $createdAt->diffForHumans(),
            'created_at_full' => $createdAt->toDayDateTimeString(),
            'runner' => $user instanceof User ? $user->name : "User #{$row->user_id}",
            'subject_label' => $label,
            'subject_url' => $url,
            'reason' => $row->reason === null ? null : str_replace('_', ' ', $row->reason->value),
            'note' => $row->note,
        ];
    }

    /**
     * A narration subject is an Analysis row id. The link resolves through the
     * same presenter the tap-through notification already uses, so a type it
     * doesn't know how to link to (most narrator types) reads as label-only
     * rather than growing a second URL map here.
     *
     * @param  Collection<int, Analysis>  $analyses
     * @return array{0: string, 1: string|null}
     */
    private function narrationSubject(int $subjectId, Collection $analyses, AnalysisMessagePresenter $presenter): array
    {
        $analysis = $analyses->get($subjectId);

        if ($analysis === null) {
            return ['narration (deleted)', null];
        }

        $type = str_replace('_', ' ', $analysis->analysis_type->value);

        return ["narration · {$type}", $presenter->url($analysis)];
    }
}
