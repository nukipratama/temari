<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Run\Story\Briefing;
use App\Services\Run\Story\Contracts\BriefingNarrator;
use App\Services\Run\Story\Contracts\VerdictNarrator;
use App\Services\Run\Story\Narrators\CachingBriefingNarrator;
use App\Services\Run\Story\Narrators\FallbackBriefingNarrator;
use App\Services\Run\Story\Narrators\LlmBriefingNarrator;
use App\Services\Run\Story\VerdictTimeline;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Override;
use SocialiteProviders\Manager\SocialiteWasCalled;
use SocialiteProviders\Strava\StravaExtendSocialite;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    #[Override]
    public function register(): void
    {
        // Verdict timeline is a pure read view onto pre-generated StoryLine
        // rows — no LLM call at request time, so it binds directly.
        $this->app->bind(VerdictNarrator::class, VerdictTimeline::class);

        // Briefing narrator: rule-based when Azure env is empty (no warning
        // in UI), Caching → Fallback → LLM chain otherwise. The chain falls
        // back to rule-based on any Throwable and flips `degraded = true`
        // so the UI surfaces a "mode darurat" chip.
        $this->app->bind(BriefingNarrator::class, function (Application $app): BriefingNarrator {
            $rules = $app->make(Briefing::class);

            // Inline check — config() values must be raw scalars (no closures)
            // for `php artisan config:cache` to work in production.
            $enabled = filled(config('azure_openai.uri')) && filled(config('azure_openai.api_key'));
            if (! $enabled) {
                return $rules;
            }

            $llm = $app->make(LlmBriefingNarrator::class);
            $fallback = new FallbackBriefingNarrator($llm, $rules);

            return new CachingBriefingNarrator($fallback);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(SocialiteWasCalled::class, StravaExtendSocialite::class);
    }
}
