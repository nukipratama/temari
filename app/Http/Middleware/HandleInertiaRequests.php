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
     * state, so the back button cannot re-render the last authenticated page
     * after logout. Enabled here rather than via `INERTIA_ENCRYPT_HISTORY`;
     * see docs/architecture/frontend-architecture.md.
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
