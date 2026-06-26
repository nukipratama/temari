<?php

declare(strict_types=1);

namespace App\Console\Commands\Telegram;

use App\Services\Telegram\Exceptions\TelegramApiException;
use App\Services\Telegram\TelegramClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Register the prod push webhook with Telegram (one call per bot). Telegram then
 * POSTs every update to /telegram/webhook with the secret token echoed in the
 * X-Telegram-Bot-Api-Secret-Token header.
 *
 * @see https://core.telegram.org/bots/api#setwebhook
 */
#[Signature('telegram:set-webhook')]
#[Description('Register the Telegram bot webhook URL and secret for prod push delivery.')]
class SetWebhookCommand extends Command
{
    public function handle(TelegramClient $client): int
    {
        if (blank(config('services.telegram.bot_token'))) {
            $this->error('TELEGRAM_BOT_TOKEN is not configured.');

            return self::FAILURE;
        }

        $secret = (string) config('services.telegram.webhook_secret');
        if ($secret === '') {
            $this->error('TELEGRAM_WEBHOOK_SECRET is not configured.');

            return self::FAILURE;
        }

        $url = route('telegram.webhook.handle');
        $this->line("Callback URL: {$url}");

        try {
            $client->setWebhook($url, $secret);
        } catch (TelegramApiException $e) {
            $this->error("Telegram rejected the webhook: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->info('Webhook registered.');

        return self::SUCCESS;
    }
}
