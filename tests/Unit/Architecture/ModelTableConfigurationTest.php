<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\AI\Analysis;
use App\Models\AI\AnalysisVersion;
use App\Models\AI\ContentFilterEvent;
use App\Models\AI\TokenUsage;
use App\Models\Analytics\DevtoolsAction;
use App\Models\Analytics\StravaRead;
use App\Models\Analytics\StravaSyncLog;
use App\Models\Feedback;
use App\Models\InboxNotification;
use App\Models\NotificationDelivery;
use App\Models\RecommendationView;
use App\Models\StravaGrantEvent;
use App\Models\StravaGrantToken;
use App\Models\TelegramLinkTokenUse;
use App\Models\TelegramUpdateReceipt;
use Illuminate\Database\Eloquent\Model;

it('resolves the pinned table configuration', function (string $class, array $configuration): void {
    /** @var Model $model */
    $model = new $class();

    expect([
        $model->getTable(),
        $model->getConnectionName(),
        $model->usesTimestamps(),
        $class::isIgnoringTouch(),
        $model->getIncrementing(),
        $model->getKeyName(),
        $model->getKeyType(),
        $model->getHidden(),
    ])->toBe($configuration);
})->with([
    [Activity::class, ['activities', null, true, false, true, 'id', 'int', ['milestone_payload']]],
    [Analysis::class, ['ai_analyses', null, true, false, true, 'id', 'int', []]],
    [AnalysisVersion::class, ['analysis_versions', null, true, false, true, 'id', 'int', []]],
    [ContentFilterEvent::class, ['ai_content_filter_events', 'analytics', false, true, true, 'id', 'int', []]],
    [TokenUsage::class, ['ai_token_usages', 'analytics', false, true, true, 'id', 'int', []]],
    [DevtoolsAction::class, ['devtools_actions', 'analytics', false, true, true, 'id', 'int', []]],
    [StravaRead::class, ['strava_reads', 'analytics', false, true, true, 'id', 'int', []]],
    [StravaSyncLog::class, ['strava_sync_logs', 'analytics', false, true, true, 'id', 'int', []]],
    [Feedback::class, ['feedback', null, true, true, true, 'id', 'int', []]],
    [InboxNotification::class, ['notifications', null, true, false, true, 'id', 'int', []]],
    [NotificationDelivery::class, ['notification_deliveries', null, false, true, true, 'id', 'int', []]],
    [RecommendationView::class, ['recommendation_views', null, false, true, true, 'id', 'int', []]],
    [StravaGrantEvent::class, ['strava_grant_events', null, false, true, true, 'id', 'int', []]],
    [StravaGrantToken::class, ['strava_grant_tokens', null, true, false, true, 'id', 'int', ['refresh_token']]],
    [TelegramLinkTokenUse::class, ['telegram_link_token_uses', null, false, true, false, 'token_hash', 'string', []]],
    [TelegramUpdateReceipt::class, ['telegram_update_receipts', null, false, true, false, 'update_id', 'int', []]],
]);
