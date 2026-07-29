/** Reads the Laravel CSRF meta tag rendered by app.blade.php. */
export function csrfToken(): string {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

/**
 * POST to a plain-JSON endpoint (the "seen"/"replay" markers that return
 * `{"ok":true}`, the analysis trigger that returns a payload). Inertia's
 * `router` rejects any non-Inertia response, so these must go through `fetch`,
 * not `router.post`. Resolves with the raw `Response` — each caller owns its
 * own error policy.
 */
export function postJson(url: string): Promise<Response> {
    return fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: '{}',
    });
}
