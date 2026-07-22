import { afterEach, describe, expect, it, vi } from 'vitest';
import { syncAppBadgeOnVisible } from './appBadge';

function setVisibility(state: DocumentVisibilityState) {
    Object.defineProperty(document, 'visibilityState', { value: state, configurable: true });
}

function stubServiceWorker(notifications: unknown[]) {
    Object.defineProperty(navigator, 'serviceWorker', {
        value: { ready: Promise.resolve({ getNotifications: vi.fn().mockResolvedValue(notifications) }) },
        configurable: true,
        writable: true,
    });
}

function stubBadge() {
    const setAppBadge = vi.fn().mockResolvedValue(undefined);
    const clearAppBadge = vi.fn().mockResolvedValue(undefined);
    Object.defineProperty(navigator, 'setAppBadge', { value: setAppBadge, configurable: true, writable: true });
    Object.defineProperty(navigator, 'clearAppBadge', { value: clearAppBadge, configurable: true, writable: true });
    return { setAppBadge, clearAppBadge };
}

afterEach(() => {
    Reflect.deleteProperty(navigator, 'serviceWorker');
    Reflect.deleteProperty(navigator, 'setAppBadge');
    Reflect.deleteProperty(navigator, 'clearAppBadge');
    vi.restoreAllMocks();
});

describe('syncAppBadgeOnVisible', () => {
    it('sets the badge to the tray count when visible', async () => {
        setVisibility('visible');
        stubServiceWorker([{}, {}, {}]);
        const { setAppBadge } = stubBadge();

        syncAppBadgeOnVisible();

        await vi.waitFor(() => expect(setAppBadge).toHaveBeenCalledWith(3));
    });

    it('clears the badge when the tray is empty', async () => {
        setVisibility('visible');
        stubServiceWorker([]);
        const { setAppBadge, clearAppBadge } = stubBadge();

        syncAppBadgeOnVisible();

        await vi.waitFor(() => expect(clearAppBadge).toHaveBeenCalled());
        expect(setAppBadge).not.toHaveBeenCalled();
    });

    // Opening the app never wipes the tray: the badge only re-syncs, so a hidden
    // page must not touch it at all.
    it('does nothing while the page is hidden', async () => {
        setVisibility('hidden');
        stubServiceWorker([{}]);
        const { setAppBadge, clearAppBadge } = stubBadge();

        syncAppBadgeOnVisible();

        await Promise.resolve();
        expect(setAppBadge).not.toHaveBeenCalled();
        expect(clearAppBadge).not.toHaveBeenCalled();
    });

    it('re-syncs when the page becomes visible again', async () => {
        setVisibility('visible');
        stubServiceWorker([{}, {}]);
        const { setAppBadge } = stubBadge();

        syncAppBadgeOnVisible();
        document.dispatchEvent(new Event('visibilitychange'));

        await vi.waitFor(() => expect(setAppBadge).toHaveBeenCalledWith(2));
    });

    it('does nothing when the Badging API is unavailable', () => {
        setVisibility('visible');
        stubServiceWorker([{}]);

        expect(() => syncAppBadgeOnVisible()).not.toThrow();
    });
});
