/** Reads the Laravel CSRF meta tag rendered by app.blade.php. */
export function csrfToken(): string {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

/**
 * Fire-and-forget POST to a plain-JSON endpoint (the "seen"/"dismiss" markers
 * that return `{"ok":true}`). Inertia's `router` rejects any non-Inertia
 * response, so these must go through `fetch`, not `router.post`. Errors are
 * swallowed — the next page load reflects the server state.
 */
export function postJson(url: string): Promise<void> {
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
    })
        .then(() => {})
        .catch(() => {});
}
