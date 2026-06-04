/**
 * Ships client-side errors to the server (`POST /client-errors`) so they reach
 * the persisted logs instead of dying in the browser console. The endpoint is
 * CSRF-exempt + IP rate-limited, so no token is needed here. Reports are
 * deduped and capped per page load to avoid flooding on an error loop.
 */
export interface ClientErrorPayload {
    message: string;
    stack?: string | null;
    url?: string | null;
    componentStack?: string | null;
}

const MAX_REPORTS = 10;
const seen = new Set<string>();
let sent = 0;

export function reportClientError(payload: ClientErrorPayload): void {
    const key = `${payload.message}::${payload.componentStack ?? payload.stack ?? ''}`.slice(0, 500);
    if (sent >= MAX_REPORTS || seen.has(key)) {
        return;
    }
    seen.add(key);
    sent += 1;

    try {
        void fetch('/client-errors', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
            body: JSON.stringify({
                message: payload.message.slice(0, 1000),
                stack: payload.stack?.slice(0, 5000) ?? null,
                url: payload.url ?? window.location.href,
                componentStack: payload.componentStack?.slice(0, 5000) ?? null,
            }),
            keepalive: true,
        });
    } catch {
        // Telemetry must never throw and break the page further.
    }
}

/** Catches errors that escape React (plain JS throws + unhandled rejections). */
export function installGlobalErrorReporting(): void {
    window.addEventListener('error', (event: ErrorEvent) => {
        reportClientError({
            message: event.message || 'Uncaught error',
            stack: event.error?.stack ?? null,
            url: window.location.href,
        });
    });

    window.addEventListener('unhandledrejection', (event: PromiseRejectionEvent) => {
        const reason = event.reason as { message?: string; stack?: string } | undefined;
        reportClientError({
            message: reason?.message ? `Unhandled rejection: ${reason.message}` : 'Unhandled promise rejection',
            stack: reason?.stack ?? null,
            url: window.location.href,
        });
    });
}
