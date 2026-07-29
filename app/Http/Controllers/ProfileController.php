<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AI\Analysis;
use App\Models\PersonalRecord;
use App\Models\User;
use App\Services\Run\LifetimeStats;
use App\Services\Run\ProgressionSeriesBuilder;
use App\Services\AI\AnalysisType;
use App\Services\AI\Narrators\PersonaSummaryNarrator;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use App\Enums\PrCategory;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * @var list<PrCategory>
     */
    private const array PROGRESSION_CATEGORIES = [
        PrCategory::Km5,
        PrCategory::Km10,
        PrCategory::HalfMarathon,
        PrCategory::Marathon,
    ];

    public function __invoke(
        Request $request,
        PersonaSummaryNarrator $personaNarrator,
        ProgressionSeriesBuilder $progressionSeriesBuilder,
        LifetimeStats $lifetimeStats,
    ): Response {
        /** @var User $user */
        $user = $request->user();

        $lifetime = $lifetimeStats->forUser($user);

        $personalRecords = PersonalRecord::query()
            ->where('user_id', $user->id)
            ->orderBy('category')
            ->get();

        $progressionByCategory = $this->buildProgressionByCategory($progressionSeriesBuilder, $user, $personalRecords);

        return Inertia::render('Aku', [
            'identity' => [
                'name' => $user->name,
                'avatar_url' => $user->avatar_url,
                'first_run_at' => $lifetime['first_run_at'],
                'member_since' => $user->created_at?->toIso8601String(),
                'strava_connected' => $user->stravaConnection !== null,
            ],
            'stats' => [
                'total_runs' => $lifetime['total_runs'],
                'total_km' => $lifetime['total_km'],
                'longest_run_km' => $lifetime['longest_km'],
            ],
            'personaMix' => $personaNarrator->personaMix($user),
            'personaSummary' => $this->resolvePersonaSummary($user),
            'profileVoice' => $this->resolveProfileVoice($user),
            'progressionByCategory' => $progressionByCategory,
        ]);
    }

    /**
     * @return array{id: int|null, status: string, content: string|null, type: string, subject_type: string, subject_id: int, discriminator: string|null}
     */
    private function resolvePersonaSummary(User $user): array
    {
        // Cache the persona summary per ISO week — moods don't shift by the
        // hour, and the narrator pulls 12 weeks of history regardless.
        $discriminator = Carbon::now()->isoFormat('GGGG-[W]WW');
        $subjectType = AnalysisType::PERSONA_SUMMARY_SUBJECT_TYPE;

        $row = Analysis::query()
            ->forSubject($subjectType, $user->id, AnalysisType::PersonaSummary, $discriminator)
            ->first();

        return Analysis::toPayload($row, AnalysisType::PersonaSummary, $subjectType, $user->id, $discriminator);
    }

    /**
     * @return array{id: int|null, status: string, content: string|null, type: string, subject_type: string, subject_id: int, discriminator: string|null}
     */
    private function resolveProfileVoice(User $user): array
    {
        $subjectType = AnalysisType::AKU_PROFILE_VOICE_SUBJECT_TYPE;

        $row = Analysis::query()
            ->forSubject($subjectType, $user->id, AnalysisType::AkuProfileVoice)
            ->first();

        return Analysis::toPayload($row, AnalysisType::AkuProfileVoice, $subjectType, $user->id);
    }

    /**
     * @param  Collection<int, PersonalRecord>  $records
     * @return array<string, array{category:string, weeks:array<int,string>, times_sec:array<int,int>, goal_sec:int|null}>
     */
    private function buildProgressionByCategory(ProgressionSeriesBuilder $builder, User $user, Collection $records): array
    {
        $prs = [];
        foreach (self::PROGRESSION_CATEGORIES as $category) {
            $pr = $records->first(fn (PersonalRecord $record): bool => $record->category === $category);
            if ($pr !== null) {
                $prs[] = $pr;
            }
        }

        return $builder->buildMany($user, $prs, fn (PersonalRecord $pr): ?int => null);
    }
}
