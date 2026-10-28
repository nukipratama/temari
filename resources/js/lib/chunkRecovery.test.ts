import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { recoverFromStaleChunks } from './chunkRecovery';

const reload = vi.fn();
let uninstall: () => void;

function preloadError(): Event {
    const event = new Event('vite:preloadError', { cancelable: true });
    window.dispatchEvent(event);
    return event;
}

/** A fresh page load: the listener re-registers, sessionStorage survives. */
function boot(): void {
    uninstall?.();
    uninstall = recoverFromStaleChunks(reload);
}

beforeEach(() => {
    vi.useFakeTimers();
    sessionStorage.clear();
    boot();
});

afterEach(() => {
    uninstall();
    vi.useRealTimers();
    reload.mockReset();
});

describe('recoverFromStaleChunks', () => {
    it('reloads in full when a chunk fails to load', () => {
        const event = preloadError();

        expect(reload).toHaveBeenCalledOnce();
        expect(event.defaultPrevented).toBe(true);
    });

    it('swallows the rest of the same failed batch while the reload is under way', () => {
        preloadError();

        const followUp = preloadError();

        expect(reload).toHaveBeenCalledOnce();
        expect(followUp.defaultPrevented).toBe(true);
    });

    it('lets a failure right after the reload surface instead of looping', () => {
        preloadError();
        reload.mockReset();
        boot();

        vi.advanceTimersByTime(2_000);
        const event = preloadError();

        expect(reload).not.toHaveBeenCalled();
        expect(event.defaultPrevented).toBe(false);
    });

    it('recovers again after a later deploy in the same session', () => {
        preloadError();
        reload.mockReset();
        boot();

        vi.advanceTimersByTime(60_000);
        preloadError();

        expect(reload).toHaveBeenCalledOnce();
    });
});
