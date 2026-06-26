<?php

declare(strict_types=1);

namespace App\Http\Controllers\Telegram;

use App\Http\Controllers\Controller;
use App\Jobs\Telegram\HandleTelegramUpdateJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Telegram Bot API webhook endpoint.
 *
 * Unauthenticated by design (Telegram calls it without a session) but gated on
 * the secret token Telegram echoes in the X-Telegram-Bot-Api-Secret-Token
 * header, set when the webhook was registered via `telegram:set-webhook`. We ack
 * with 200 quickly and push the linking work onto the queue.
 *
 * @see https://core.telegram.org/bots/api#setwebhook
 */
class TelegramWebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        $expected = (string) config('services.telegram.webhook_secret');
        $provided = (string) $request->header('X-Telegram-Bot-Api-Secret-Token', '');

        if ($expected === '' || ! hash_equals($expected, $provided)) {
            Log::warning('telegram.webhook rejected — secret token mismatch');

            return response()->json(['error' => 'invalid secret token'], Response::HTTP_FORBIDDEN);
        }

        /** @var array<string, mixed> $update */
        $update = $request->all();
        HandleTelegramUpdateJob::dispatch($update);

        return response()->json(['ok' => true]);
    }
}
