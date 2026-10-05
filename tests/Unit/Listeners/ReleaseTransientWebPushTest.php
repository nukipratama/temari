<?php

declare(strict_types=1);

use App\Exceptions\Notifications\TransientWebPushException;
use App\Listeners\ReleaseTransientWebPush;
use App\Models\User;
use App\Notifications\Channels\IdempotentWebPushChannel;
use App\Notifications\TestNotification;
use Base64Url\Base64Url;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Minishlink\WebPush\VAPID;

uses(RefreshDatabase::class);

function queuedPushUser(): User
{
    $vapid = VAPID::createVapidKeys();
    config(['webpush.vapid.public_key' => $vapid['publicKey'], 'webpush.vapid.private_key' => $vapid['privateKey']]);
    $device = openssl_pkey_get_details(openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]))['ec'];
    $user = User::factory()->create();
    $user->updatePushSubscription(
        'https://push.example/endpoint',
        Base64Url::encode("\x04".str_pad($device['x'], 32, "\0", STR_PAD_LEFT).str_pad($device['y'], 32, "\0", STR_PAD_LEFT)),
        Base64Url::encode(random_bytes(16)),
    );

    return $user;
}

function workQueuedPush(): int
{
    test()->artisan('queue:work', ['connection' => 'database', '--stop-when-empty' => true, '--memory' => 2048, '--sleep' => 0.01]);

    $job = DB::table('jobs')->get()->sole(fn (object $job): bool => str_contains($job->payload, addslashes(IdempotentWebPushChannel::class)));

    return $job->available_at - now()->getTimestamp();
}

it('retries a throttled queued push after the push service Retry-After', function (): void {
    config(['queue.default' => 'database']);
    $this->freezeTime();
    Http::fake(['push.example/*' => Http::response('', 429, ['Retry-After' => '600'])]);

    queuedPushUser()->notify(new TestNotification());

    expect(workQueuedPush())->toBe(600);
});

it('leaves a queued push without Retry-After on the notification backoff', function (): void {
    config(['queue.default' => 'database']);
    $this->freezeTime();
    Http::fake(['push.example/*' => Http::response('', 503)]);

    queuedPushUser()->notify(new TestNotification());

    expect(workQueuedPush())->toBe(30);
});

it('does not release a job that already failed, was released or was deleted', function (string $state): void {
    $job = Mockery::mock(Job::class);
    $job->allows(['hasFailed' => $state === 'failed', 'isReleased' => $state === 'released', 'isDeleted' => $state === 'deleted']);
    $job->shouldNotReceive('release');

    new ReleaseTransientWebPush()->handle(new JobExceptionOccurred('database', $job, new TransientWebPushException('busy', 60)));
})->with(['failed', 'released', 'deleted']);

it('ignores other exceptions and transient failures without Retry-After', function (Throwable $exception): void {
    $job = Mockery::mock(Job::class);
    $job->shouldNotReceive('release');

    new ReleaseTransientWebPush()->handle(new JobExceptionOccurred('database', $job, $exception));
})->with([
    'other exception' => [new RuntimeException('boom')],
    'no Retry-After' => [new TransientWebPushException('busy')],
]);
