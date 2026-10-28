import { afterEach, describe, expect, it, vi } from 'vitest';

import { syncAppBadge, syncAppBadgeOnVisible } from './appBadge';

const signedIn = (unread: unknown) => ({
    auth: { user: { id: 1 } },
    unreadNotifications: unread,
});
const guest = { auth: { user: null }, unreadNotifications: 0 };

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

        syncAppBadge(signedIn(3));

        await vi.waitFor(() => expect(setAppBadge).toHaveBeenCalledWith(3));
    });

    it('clears the badge once the inbox is read', async () => {
        const { setAppBadge, clearAppBadge } = stubBadge();

        syncAppBadge(signedIn(2));
        syncAppBadge(signedIn(0));

        await vi.waitFor(() => expect(clearAppBadge).toHaveBeenCalled());
        expect(setAppBadge).toHaveBeenCalledTimes(1);
    });

    it('clears the badge on a guest page, so signing out leaves none behind', async () => {
        const { clearAppBadge } = stubBadge();

        syncAppBadge(signedIn(4));
        syncAppBadge(guest);

        await vi.waitFor(() => expect(clearAppBadge).toHaveBeenCalled());
    });

    it('treats a missing or malformed count as zero', async () => {
        const { clearAppBadge } = stubBadge();

        syncAppBadge(signedIn('5'));

        await vi.waitFor(() => expect(clearAppBadge).toHaveBeenCalled());
    });

    it('does nothing when the Badging API is unavailable', () => {
        expect(() => syncAppBadge(signedIn(1))).not.toThrow();
    });
});

// A cached count would miss reads made on another device or in the inbox
// while the app was backgrounded, so resume asks the server again.
describe('syncAppBadgeOnVisible', () => {
    it('refetches the unread count when a signed-in page comes back into view', () => {
        setVisibility('visible');
        const refresh = vi.fn();
        syncAppBadge(signedIn(4));

        syncAppBadgeOnVisible(refresh);
        document.dispatchEvent(new Event('visibilitychange'));

        expect(refresh).toHaveBeenCalledOnce();
    });

    it('does not refetch while the page is hidden', () => {
        setVisibility('hidden');
        const refresh = vi.fn();
        syncAppBadge(signedIn(1));

        syncAppBadgeOnVisible(refresh);
        document.dispatchEvent(new Event('visibilitychange'));

        expect(refresh).not.toHaveBeenCalled();
    });

    it('does not refetch for a guest', () => {
        setVisibility('visible');
        const refresh = vi.fn();
        syncAppBadge(guest);

        syncAppBadgeOnVisible(refresh);
        document.dispatchEvent(new Event('visibilitychange'));

        expect(refresh).not.toHaveBeenCalled();
    });

    it('does not refetch at registration time, only on a later event', () => {
        setVisibility('visible');
        const refresh = vi.fn();
        syncAppBadge(signedIn(5));

        syncAppBadgeOnVisible(refresh);

        expect(refresh).not.toHaveBeenCalled();
    });
});
