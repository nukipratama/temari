/**
 * The PWA app-icon badge, which counts the in-app inbox's unread rows — the
 * same number the bell shows, so the icon and the app never disagree.
 *
 * {@see syncAppBadge} is called with the `unreadNotifications` shared prop on
 * every Inertia visit, which is also what clears the badge the moment the inbox
 * is read. {@see syncAppBadgeOnVisible} re-applies the last count when the app
 * comes back into view, for the iOS case where a swiped-away notification
 * leaves the service worker's own count stale ({@see public/sw.js}).
 *
 * Fire-and-forget, matching {@see registerServiceWorker}: any absent API or
 * rejected promise degrades silently.
 */
type BadgeNavigator = Navigator & {
    setAppBadge?: (count?: number) => Promise<void>;
    clearAppBadge?: () => Promise<void>;
};

let lastKnownUnread = 0;

function apply(count: number): void {
    if (typeof navigator === 'undefined' || !('setAppBadge' in navigator)) {
        return;
    }

    const badgeNavigator = navigator as BadgeNavigator;
    const applied =
        count > 0
            ? badgeNavigator.setAppBadge?.(count)
            : badgeNavigator.clearAppBadge?.();

    void applied?.catch(() => undefined);
}

export function syncAppBadge(unread: number): void {
    lastKnownUnread = unread;
    apply(unread);
}

export function syncAppBadgeOnVisible(): void {
    const sync = (): void => {
        if (document.visibilityState !== 'visible') {
            return;
        }

        apply(lastKnownUnread);
    };

    document.addEventListener('visibilitychange', sync);
    globalThis.addEventListener('focus', sync);
    sync();
}
