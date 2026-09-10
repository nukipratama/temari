<?php

declare(strict_types=1);

namespace App\Services\Devtools;

use App\Models\Analytics\DevtoolsAction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Request;
use Throwable;

/**
 * Writes the /devtools audit trail. The actor is the HTTP Basic username the
 * devtools gate accepted; outside a request that carries one (local dev, a
 * console command) it is {@see self::LOCAL_ACTOR}.
 */
class DevtoolsActionRecorder
{
    public const string LOCAL_ACTOR = 'local';

    /**
     * @param  array<string, mixed>  $payload
     */
    public function record(string $action, ?int $userId = null, array $payload = []): void
    {
        try {
            DevtoolsAction::query()->create([
                'actor' => $this->actor(),
                'action' => $action,
                'user_id' => $userId,
                'payload' => $payload === [] ? null : $payload,
                'created_at' => Carbon::now(),
            ]);
        } catch (Throwable $e) {
            // The action itself already ran; losing its audit row must not turn a
            // successful re-arm into a 500.
            Log::warning('devtools_action.record_failed', [
                'action' => $action,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function actor(): string
    {
        $user = Request::getUser();

        return is_string($user) && $user !== '' ? $user : self::LOCAL_ACTOR;
    }
}
