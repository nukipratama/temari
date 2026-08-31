<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\Geo\ResolveActivityLocationJob;
use App\Models\Activity;
use App\Models\AI\Analysis;
use App\Models\RunCard;
use App\Models\StoryLine;
use App\Models\User;
use App\Services\AI\AnalysisType;
use App\Services\Run\Ingest\DetailHydrator;
use App\Services\Run\Story\CardPresenter;
use App\Services\Run\Story\PastYouMatcher;
use App\Services\Run\Story\Temari;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class RunController extends Controller
{
    private const array RUN_INSIGHT_TYPES = [
        AnalysisType::PostRunSpeech,
        AnalysisType::RunInsight,
    ];

    /**
     * How long one run-detail view reserves the right to re-dispatch a location
     * resolve. `ShouldBeUnique` only dedupes while the job is queued or running,
     * so this guard covers the gap after a transient Nominatim miss finishes
     * without stamping `location_resolved_at`. Matched to the job's own
     * `uniqueFor`.
     */
    private const int LOCATION_DISPATCH_GUARD_SECONDS = 600;

    public function show(Request $request, Activity $activity, PastYouMatcher $matcher, CardPresenter $cards, DetailHydrator $hydrator): Response
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->can('view', $activity), 404);

        $activity->loadMissing(['detail', 'runCard']);
        $detail = $activity->detail;
        abort_if($detail === null, 404, 'Activity not yet analyzed.');

        $awaitingDetail = $hydrator->hydrate($activity->id);

        if ($detail->start_lat !== null
            && $detail->location_resolved_at === null
            && Cache::lock("geo:resolve-dispatch:{$detail->id}", self::LOCATION_DISPATCH_GUARD_SECONDS)->get()) {
            ResolveActivityLocationJob::dispatch($detail->id);
        }

        // Deferred behind a closure (skipped on a partial reload that doesn't
        // name it) and memoized, since the insight props plus the notification
        // cooldown all read it.
        /** @var Collection<string, Analysis>|null $loadedAnalyses */
        $loadedAnalyses = null;
        $loadAnalyses = function () use ($activity, &$loadedAnalyses): Collection {
            /** @var Collection<string, Analysis> */
            return $loadedAnalyses ??= Analysis::query()
                ->where('subject_type', Activity::class)
                ->where('subject_id', $activity->id)
                ->whereIn('analysis_type', self::RUN_INSIGHT_TYPES)
                ->get()
                ->keyBy(fn (Analysis $row): string => $row->analysis_type->value);
        };

        $payloadFor = fn (AnalysisType $type): array => Analysis::toPayload(
            $loadAnalyses()->get($type->value),
            $type,
            Activity::class,
            $activity->id,
        );

        return Inertia::render('Runs/Show', [
            // `activity` and `detail` are already hydrated for the 404 guards
            // above, so a closure would defer nothing.
            'activity' => $activity,
            'detail' => $detail,
            // True only when this view queued the deeper fetch, so the notice
            // promising "it fills itself in" is never shown to a run nothing is
            // coming for (demo data, a revoked connection, an already-detailed row).
            'awaitingDetail' => $awaitingDetail,
            'card' => fn (): ?array => $this->cardPayload($cards, $activity->runCard, $user),
            'storyLine' => fn (): ?StoryLine => StoryLine::query()
                ->where('activity_id', $activity->id)
                ->where('kind', StoryLine::KIND_POST_RUN)
                ->first(),
            // Backend-computed mood for the (rare) window before the post-run
            // StoryLine lands, so the detail mascot matches the share card
            // instead of diverging into a frontend heuristic.
            'moodFallback' => fn (): string => Temari::moodForActivityOrDefault($activity),
            // Per-activity narration is a connected + chained kind: only the chain
            // head (the user's latest run) may regenerate ("Reread"); historical
            // runs are resume-only, so re-narrating mid-history can't desync the
            // later runs that quoted their old narrative.
            'isChainHead' => fn (): bool => Activity::latestIdForUser($user->id) === $activity->id,
            'speechAnalysis' => fn (): array => $payloadFor(AnalysisType::PostRunSpeech),
            'notificationRetryAfterSeconds' => fn (): ?int => Analysis::notificationCooldownRemaining(
                $payloadFor(AnalysisType::PostRunSpeech),
            ),
            'runInsight' => fn (): array => $payloadFor(AnalysisType::RunInsight),
            'pastYou' => function () use ($matcher, $hydrator, $activity, $detail): ?array {
                $match = $matcher->findMatch($activity, $detail);
                if ($match !== null) {
                    $hydrator->hydrate($match['past']->activity_id);
                }

                return $match;
            },
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function cardPayload(CardPresenter $cards, ?RunCard $card, User $user): ?array
    {
        if ($card === null) {
            return null;
        }

        return [
            ...$cards->base($card),
            'flavor_analysis' => $cards->flavorAnalysis($card),
            'edition' => $cards->edition($card, $user->id),
            'public_share_url' => route('activities.show', ['activity' => $card->activity_id]),
        ];
    }
}
