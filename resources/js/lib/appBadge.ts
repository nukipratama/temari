/**
 * The PWA app-icon badge, which counts the in-app inbox's unread rows — the
 * same number the bell shows. The server's count is the only source: this
 * module applies the `unreadNotifications` shared prop on every Inertia visit,
 * and the service worker applies the count a push carries ({@see public/sw.js}).
 *
 * {@see syncAppBadgeOnVisible} refetches that prop when the app comes back into
 * view, so reads made elsewhere while it was backgrounded show up.
 *
 * Fire-and-forget, matching {@see registerServiceWorker}: any absent API or
 * rejected promise degrades silently.
 */
type BadgeNavigator = Navigator & {
    setAppBadge?: (count?: number) => Promise<void>;
    clearAppBadge?: () => Promise<void>;
};

let signedIn = false;

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

/** Applies an Inertia page's unread count; a guest page clears the badge. */
export function syncAppBadge(props: Record<string, unknown>): void {
    const auth = props.auth as { user?: unknown } | undefined;
    const unread = props.unreadNotifications;

    signedIn = auth?.user != null;
    apply(signedIn && typeof unread === 'number' ? unread : 0);
}

export function syncAppBadgeOnVisible(refresh: () => void): void {
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible' && signedIn) {
            refresh();
        }
    });
}
