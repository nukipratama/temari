<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Inertia\SharedProps;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Middleware;
use Override;
use Symfony\Component\HttpFoundation\Response;

class HandleInertiaRequests extends Middleware
{
    #[Override]
    protected $rootView = 'app';

    public function __construct(private readonly SharedProps $props)
    {
    }

    /**
     * Encrypt the page object before it is written to the browser's history
     * state.
     *
     * Inertia stores every page's full props in `window.history.state` so
     * back/forward can re-render without a round trip. Unencrypted, that leaves
     * the user's run history, pace/HR, training load, AI narration and the
     * shared auth block sitting in plaintext in the session history — and,
     * worse, makes `Inertia::clearHistory()` on logout a no-op, so the back
     * button still re-renders the last authenticated page after signing out.
     *
     * Enabled here rather than via `INERTIA_ENCRYPT_HISTORY` so it is not one
     * missing env var away from silently reverting. The AES-GCM key lives in
     * sessionStorage and needs a secure context, which prod (HTTPS) and local
     * (localhost) both are.
     */
    #[Override]
    public function handle(Request $request, Closure $next): Response
    {
        Inertia::encryptHistory();

        return parent::handle($request, $next);
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            ...$this->props->forRequest($request),
        ];
    }
}
