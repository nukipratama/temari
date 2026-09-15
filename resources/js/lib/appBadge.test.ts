import { afterEach, describe, expect, it, vi } from 'vitest';

import { syncAppBadge, syncAppBadgeOnVisible } from './appBadge';

function setVisibility(state: DocumentVisibilityState) {
    Object.defineProperty(document, 'visibilityState', {
        value: state,
        configurable: true,
    });
}

function stubBadge() {
    const setAppBadge = vi.fn().mockResolvedValue(undefined);
    const clearAppBadge = vi.fn().mockResolvedValue(undefined);
    Object.defineProperty(navigator, 'setAppBadge', {
        value: setAppBadge,
        configurable: true,
        writable: true,
    });
    Object.defineProperty(navigator, 'clearAppBadge', {
        value: clearAppBadge,
        configurable: true,
        writable: true,
    });
    return { setAppBadge, clearAppBadge };
}

afterEach(() => {
    Reflect.deleteProperty(navigator, 'setAppBadge');
    Reflect.deleteProperty(navigator, 'clearAppBadge');
    vi.restoreAllMocks();
});

describe('syncAppBadge', () => {
    it('sets the badge to the inbox unread count', async () => {
        const { setAppBadge } = stubBadge();

        syncAppBadge(3);

        await vi.waitFor(() => expect(setAppBadge).toHaveBeenCalledWith(3));
    });

    it('clears the badge once the inbox is read', async () => {
        const { setAppBadge, clearAppBadge } = stubBadge();

        syncAppBadge(2);
        syncAppBadge(0);

        await vi.waitFor(() => expect(clearAppBadge).toHaveBeenCalled());
        expect(setAppBadge).toHaveBeenCalledTimes(1);
    });

    it('does nothing when the Badging API is unavailable', () => {
        expect(() => syncAppBadge(1)).not.toThrow();
    });
});

describe('syncAppBadgeOnVisible', () => {
    it('re-applies the last known unread count when the page becomes visible', async () => {
        setVisibility('visible');
        const { setAppBadge } = stubBadge();

        syncAppBadge(4);
        setAppBadge.mockClear();

        syncAppBadgeOnVisible();
        document.dispatchEvent(new Event('visibilitychange'));

        await vi.waitFor(() => expect(setAppBadge).toHaveBeenCalledWith(4));
    });

    it('does nothing while the page is hidden', async () => {
        const { setAppBadge, clearAppBadge } = stubBadge();

        syncAppBadge(1);
        setAppBadge.mockClear();
        clearAppBadge.mockClear();
        setVisibility('hidden');

        syncAppBadgeOnVisible();

        await Promise.resolve();
        expect(setAppBadge).not.toHaveBeenCalled();
        expect(clearAppBadge).not.toHaveBeenCalled();
    });
});
