/**
 * A short vibration for the moments that deserve a physical confirmation — a
 * notification sent, a card revealed; not wired to every tap.
 *
 * Progressive enhancement only: **iOS Safari does not implement
 * `navigator.vibrate`**, so on an installed iOS PWA these calls are silently
 * inert and nothing in the UI may depend on haptics firing.
 */

/** Milliseconds. Short enough to read as a tick, not a buzz. */
const COMMIT_MS = 18;

function vibrate(pattern: number): void {
    // `vibrate` is absent on iOS Safari and can throw in an embedded webview,
    // so both the capability check and the call itself are guarded.
    if (
        typeof navigator === 'undefined' ||
        typeof navigator.vibrate !== 'function'
    ) {
        return;
    }

    try {
        navigator.vibrate(pattern);
    } catch {
        // A refused vibration is never worth surfacing.
    }
}

/** A slightly heavier beat for a completed action (send, reveal). */
export function hapticCommit(): void {
    vibrate(COMMIT_MS);
}
