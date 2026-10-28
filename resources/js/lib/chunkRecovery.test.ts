import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { recoverFromStaleChunks } from './chunkRecovery';

const reload = vi.fn();

recoverFromStaleChunks(reload);

function preloadError(): Event {
    const event = new Event('vite:preloadError', { cancelable: true });
    window.dispatchEvent(event);
    return event;
}

beforeEach(() => {
    vi.useFakeTimers();
    sessionStorage.clear();
});

afterEach(() => {
    vi.useRealTimers();
    reload.mockReset();
});

describe('recoverFromStaleChunks', () => {
    it('reloads in full when a chunk fails to load', () => {
        const event = preloadError();

        expect(reload).toHaveBeenCalledOnce();
        expect(event.defaultPrevented).toBe(true);
    });

    it('lets a second failure right after the reload surface instead of looping', () => {
        preloadError();
        reload.mockReset();

        vi.advanceTimersByTime(2_000);
        const event = preloadError();

        expect(reload).not.toHaveBeenCalled();
        expect(event.defaultPrevented).toBe(false);
    });

    it('recovers again after a later deploy in the same session', () => {
        preloadError();
        reload.mockReset();

        vi.advanceTimersByTime(60_000);
        preloadError();

        expect(reload).toHaveBeenCalledOnce();
    });
});
