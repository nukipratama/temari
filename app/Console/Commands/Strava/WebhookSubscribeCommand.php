<?php

declare(strict_types=1);

namespace App\Console\Commands\Strava;

use App\Services\Strava\StravaWebhookProbe;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Manage the Strava push subscription (one per application).
 *
 * @see https://developers.strava.com/docs/webhooks/
 */
#[Signature('strava:webhook-subscribe
    {--action=view : ensure | create | view | delete}
    {--id= : Subscription id to delete (required for --action=delete)}')]
#[Description('Create, view, or delete the Strava webhook push subscription.')]
class WebhookSubscribeCommand extends Command
{
    private const string SUBSCRIPTIONS_URL = 'https://www.strava.com/api/v3/push_subscriptions';

    public function handle(): int
    {
        $clientId = config('services.strava.client_id');
        $clientSecret = config('services.strava.client_secret');

        if (blank($clientId) || blank($clientSecret)) {
            $this->error('Strava client_id / client_secret are not configured.');

            return self::FAILURE;
        }

        return match ($this->option('action')) {
            'ensure' => $this->ensure((string) $clientId, (string) $clientSecret),
            'create' => $this->create((string) $clientId, (string) $clientSecret),
            'view' => $this->view((string) $clientId, (string) $clientSecret),
            'delete' => $this->delete((string) $clientId, (string) $clientSecret),
            default => $this->invalidAction(),
        };
    }

    /**
     * Idempotent create: skip if a matching subscription already exists, refuse
     * (with guidance) if a stale one with a different callback blocks the single
     * per-app slot, otherwise create.
     */
    private function ensure(string $clientId, string $clientSecret): int
    {
        $subscriptions = $this->fetchSubscriptions($clientId, $clientSecret);
        if ($subscriptions === null) {
            return self::FAILURE;
        }

        $callbackUrl = route('strava.webhook.verify');

        foreach ($subscriptions as $subscription) {
            if (($subscription['callback_url'] ?? null) === $callbackUrl) {
                $this->info("Already subscribed (id={$subscription['id']}), callback {$callbackUrl}.");

                return self::SUCCESS;
            }
        }

        if ($subscriptions !== []) {
            $existing = $subscriptions[0];
            $this->warn("A subscription already exists with a different callback ({$existing['callback_url']}, id={$existing['id']}).");
            $this->line("Strava allows one subscription per app — delete it first: php artisan strava:webhook-subscribe --action=delete --id={$existing['id']}");

            return self::FAILURE;
        }

        return $this->create($clientId, $clientSecret);
    }

    private function create(string $clientId, string $clientSecret): int
    {
        $verifyToken = config('services.strava.webhook_verify_token');
        if (blank($verifyToken)) {
            $this->error('STRAVA_WEBHOOK_VERIFY_TOKEN is not configured.');

            return self::FAILURE;
        }

        $callbackUrl = route('strava.webhook.verify');
        $this->line("Callback URL: {$callbackUrl}");
        $this->line('Verify token length: '.mb_strlen((string) $verifyToken));

        // Strava synchronously GETs the callback during create and only accepts
        // the subscription if it echoes the challenge. Probe it ourselves first
        // so a failure points at the real cause (stale container env, Cloudflare
        // intercepting the bot, unreachable URL) instead of a blind Strava 4xx.
        $probe = app(StravaWebhookProbe::class)->probe($callbackUrl, (string) $verifyToken);
        if (! $probe['passed']) {
            $this->error("Self-verify failed ({$probe['status']}): the callback did not echo the challenge.");
            $this->line('Response: '.$probe['detail']);
            $this->error('Aborting before calling Strava: the public callback did not pass its own verify handshake. Fix that (recreate the app container so it loads the verify token, or allow /strava/webhook through Cloudflare), then retry.');

            return self::FAILURE;
        }

        $this->info('Self-verify passed: callback echoed the challenge.');

        $response = Http::asForm()->post(self::SUBSCRIPTIONS_URL, [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'callback_url' => $callbackUrl,
            'verify_token' => $verifyToken,
        ]);

        if ($response->failed()) {
            $this->error("Strava rejected the subscription ({$response->status()}): {$response->body()}");

            return self::FAILURE;
        }

        $id = $response->json('id');
        $this->info("Subscription created with id {$id}.");
        $this->line('Set STRAVA_WEBHOOK_SUBSCRIPTION_ID in your env to record it.');

        return self::SUCCESS;
    }

    private function view(string $clientId, string $clientSecret): int
    {
        $subscriptions = $this->fetchSubscriptions($clientId, $clientSecret);
        if ($subscriptions === null) {
            return self::FAILURE;
        }

        if ($subscriptions === []) {
            $this->warn('No active push subscription.');

            return self::SUCCESS;
        }

        foreach ($subscriptions as $subscription) {
            $id = $subscription['id'] ?? '?';
            $callback = $subscription['callback_url'] ?? '?';
            $this->line("id={$id}  callback={$callback}");
        }

        return self::SUCCESS;
    }

    private function delete(string $clientId, string $clientSecret): int
    {
        $id = $this->option('id') ?? config('services.strava.webhook_subscription_id');
        if (blank($id)) {
            $this->error('Pass --id=<subscription id> (or set STRAVA_WEBHOOK_SUBSCRIPTION_ID).');

            return self::FAILURE;
        }

        $response = Http::asForm()->delete(self::SUBSCRIPTIONS_URL.'/'.$id, [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
        ]);

        if ($response->failed()) {
            $this->error("Could not delete subscription {$id} ({$response->status()}): {$response->body()}");

            return self::FAILURE;
        }

        $this->info("Subscription {$id} deleted.");

        return self::SUCCESS;
    }

    /**
     * Fetch the app's current push subscriptions (0 or 1). Returns null on a
     * failed request so callers can short-circuit; an empty list is a success.
     *
     * @return list<array<string, mixed>>|null
     */
    private function fetchSubscriptions(string $clientId, string $clientSecret): ?array
    {
        $response = Http::get(self::SUBSCRIPTIONS_URL, [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
        ]);

        if ($response->failed()) {
            $this->error("Could not fetch subscriptions ({$response->status()}): {$response->body()}");

            return null;
        }

        $subscriptions = $response->json();

        return is_array($subscriptions) ? array_values($subscriptions) : [];
    }

    private function invalidAction(): int
    {
        $this->error('Unknown --action. Use one of: ensure, create, view, delete.');

        return self::FAILURE;
    }
}
