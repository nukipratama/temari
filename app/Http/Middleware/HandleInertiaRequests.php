<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Activity;
use App\Models\AI\Analysis;
use App\Models\PersonalRecord;
use App\Models\RunCard;
use App\Models\User;
use App\Models\UserUnlock;
use App\Models\WeeklySnapshot;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use Illuminate\Http\Request;
use Inertia\Middleware;
use Override;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function share(Request $request): array
    {
        /** @var User|null $user */
        $user = $request->user();

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user === null ? null : [
                    'id' => $user->id,
                    'name' => $user->name,
                    'first_name' => $user->firstName(),
                    'avatar_url' => $user->avatar_url ?? null,
                ],
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'info' => fn () => $request->session()->get('info'),
            ],
            'demoLoginEnabled' => (bool) config('demo.login_enabled'),
            'onboarding' => [
                'forceShow' => (bool) config('onboarding.force_show'),
            ],
            'unlockedAccessories' => fn () => $user === null
                ? []
                : UserUnlock::query()->where('user_id', $user->id)->pluck('unlock_key')->all(),
            'aiActivity' => $this->aiActivityCounts($user),
        ];
    }

    /**
     * @return array{pending: int, queued: int, processing: int}
     */
    private function aiActivityCounts(?User $user): array
    {
        if ($user === null) {
            return ['pending' => 0, 'queued' => 0, 'processing' => 0];
        }

        $userDaySubjectTypes = [
            AnalysisType::BRIEFING_SUBJECT_TYPE,
            AnalysisType::DAILY_GREETING_SUBJECT_TYPE,
            AnalysisType::TREND_CAPTION_SUBJECT_TYPE,
        ];

        $base = Analysis::query()
            ->whereIn('status', [AnalysisStatus::Pending, AnalysisStatus::Queued, AnalysisStatus::Processing])
            ->where(function ($q) use ($user, $userDaySubjectTypes): void {
                $q->where(function ($q2) use ($user, $userDaySubjectTypes): void {
                    $q2->whereIn('subject_type', $userDaySubjectTypes)
                        ->where('subject_id', $user->id);
                })
                    ->orWhere(function ($q2) use ($user): void {
                        $q2->where('subject_type', Activity::class)
                            ->whereIn('subject_id', Activity::query()->where('user_id', $user->id)->select('id'));
                    })
                    ->orWhere(function ($q2) use ($user): void {
                        $q2->where('subject_type', PersonalRecord::class)
                            ->whereIn('subject_id', PersonalRecord::query()->where('user_id', $user->id)->select('id'));
                    })
                    ->orWhere(function ($q2) use ($user): void {
                        $q2->where('subject_type', WeeklySnapshot::class)
                            ->whereIn('subject_id', WeeklySnapshot::query()->where('user_id', $user->id)->select('id'));
                    })
                    ->orWhere(function ($q2) use ($user): void {
                        $q2->where('subject_type', RunCard::class)
                            ->whereIn(
                                'subject_id',
                                RunCard::query()
                                    ->whereHas('activity', fn ($a) => $a->where('user_id', $user->id))
                                    ->select('id'),
                            );
                    });
            });

        $rows = (clone $base)
            ->selectRaw('status, COUNT(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status');

        return [
            'pending' => (int) ($rows[AnalysisStatus::Pending->value] ?? 0),
            'queued' => (int) ($rows[AnalysisStatus::Queued->value] ?? 0),
            'processing' => (int) ($rows[AnalysisStatus::Processing->value] ?? 0),
        ];
    }
}
