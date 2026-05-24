/** Reads the Laravel CSRF meta tag rendered by app.blade.php. */
export function csrfToken(): string {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}
