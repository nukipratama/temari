<?php

declare(strict_types=1);

namespace App\Console\Commands\Telegram;

use App\Jobs\Telegram\HandleTelegramUpdateJob;
use App\Services\Telegram\TelegramClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\App;

/**
 * Dev-only manual foreground listener (like `queue:listen`, never scheduled).
 * Long-polls Telegram's getUpdates and feeds each update to the same
 * {@see HandleTelegramUpdateJob} the prod webhook feeds, so local linking can be
 * tested with no public URL. Run against the test bot (which has no webhook set;
 * Telegram 409s getUpdates while a webhook is registered). Ctrl-C to stop.
 */
#[Signature('telegram:listen {--max-batches=0 : stop after N poll batches (0 = run forever)}')]
#[Description('Dev: long-poll Telegram for bot updates and process them locally (no webhook needed).')]
class ListenCommand extends Command
{
    private const int POLL_TIMEOUT_SECONDS = 30;

    public function handle(TelegramClient $client): int
    {
        if (App::environment('production')) {
            $this->error('telegram:listen is a dev tool; prod uses the webhook (telegram:set-webhook).');

            return self::FAILURE;
        }

        if (blank(config('services.telegram.bot_token'))) {
            $this->error('TELEGRAM_BOT_TOKEN is not configured.');

            return self::FAILURE;
        }

        $maxBatches = (int) $this->option('max-batches');
        $this->info('Listening for Telegram updates (Ctrl-C to stop)...');

        $offset = 0;
        $batches = 0;

        while ($maxBatches === 0 || $batches < $maxBatches) {
            foreach ($client->getUpdates($offset, self::POLL_TIMEOUT_SECONDS) as $update) {
                HandleTelegramUpdateJob::dispatchSync($update);
                $offset = max($offset, (int) ($update['update_id'] ?? 0) + 1);
            }

            $batches++;
        }

        return self::SUCCESS;
    }
}
