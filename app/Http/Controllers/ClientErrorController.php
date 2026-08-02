<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\ReportClientErrorRequest;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Sink for client-side errors (React render errors, unhandled rejections) so
 * they reach the persisted logs instead of dying in the browser console. Note:
 * this lands in the logs only, NOT the Pulse Exceptions card (Pulse records
 * reported server exceptions, not Log:: calls).
 */
class ClientErrorController extends Controller
{
    private const int MAX_TRACE_CHARS = 2000;

    public function __invoke(ReportClientErrorRequest $request): Response
    {
        $validated = $request->validated();

        Log::channel('client-errors')->warning('client-error', [
            'message' => $validated['message'],
            'url' => $validated['url'] ?? $request->headers->get('referer'),
            'component_stack' => self::trace($validated['componentStack'] ?? null),
            'stack' => self::trace($validated['stack'] ?? null),
            'user_id' => $request->user()?->id,
        ]);

        return response()->noContent();
    }

    /**
     * Accepts the wider length a stale cached bundle may still send, but only
     * ever writes the leading frames — the tail of a stack is rarely the part
     * that identifies the bug.
     */
    private static function trace(?string $value): ?string
    {
        return $value === null ? null : Str::limit($value, self::MAX_TRACE_CHARS);
    }
}
