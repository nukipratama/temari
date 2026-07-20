/**
 * A short vibration for the moments that deserve a physical confirmation — a
 * notification sent, an accessory equipped, a card unlocked. Deliberately not
 * wired to every tap: the visual `.pressable` shrink is the cross-platform
 * touch feedback, this is the extra beat on a real commit.
 *
 * Progressive enhancement only. **iOS Safari does not implement
 * `navigator.vibrate` at all**, so on the primary target (an installed iOS PWA)
 * these calls are silently inert; Android gets the buzz. That asymmetry is why
 * nothing in the UI is allowed to depend on haptics firing.
 */

/** Milliseconds. Short enough to read as a tick, not a buzz. */
const TAP_MS = 10;
const COMMIT_MS = 18;

function vibrate(pattern: number): void {
    // `vibrate` is absent on iOS Safari and can throw in an embedded webview,
    // so both the capability check and the call itself are guarded.
    if (typeof navigator === 'undefined' || typeof navigator.vibrate !== 'function') {
        return;
    }

    try {
        navigator.vibrate(pattern);
    } catch {
        // A refused vibration is never worth surfacing.
    }
}

/** A light tick for a confirmed tap. */
export function hapticTap(): void {
    vibrate(TAP_MS);
}

/** A slightly heavier beat for a completed action (send, equip, unlock). */
export function hapticCommit(): void {
    vibrate(COMMIT_MS);
}
