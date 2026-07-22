/**
 * Re-sync the PWA app-icon badge to the notification tray whenever the app
 * becomes visible.
 *
 * The service worker keeps the badge live as pushes arrive and as notifications
 * are tapped or swiped ({@see public/sw.js}). This covers the one case it can't:
 * on iOS the `notificationclose` event never fires, so a swiped-away
 * notification leaves a stale count until the app is next opened. Reading the
 * same `getNotifications()` tray the worker does, on `visibilitychange`, makes
 * the count correct again. It never clears the badge on its own and never
 * dismisses a notification — the tray is the single source of truth.
 *
 * Fire-and-forget, matching {@see registerServiceWorker}: any absent API or
 * rejected promise degrades silently.
 */
type BadgeNavigator = Navigator & {
    setAppBadge?: (count?: number) => Promise<void>;
    clearAppBadge?: () => Promise<void>;
};

export function syncAppBadgeOnVisible(): void {
    if (typeof navigator === 'undefined' || !('serviceWorker' in navigator) || !('setAppBadge' in navigator)) {
        return;
    }

    const badgeNavigator = navigator as BadgeNavigator;

    const sync = (): void => {
        if (document.visibilityState !== 'visible') {
            return;
        }

        void navigator.serviceWorker.ready
            .then((registration) => registration.getNotifications())
            .then((notifications) =>
                notifications.length > 0 ? badgeNavigator.setAppBadge?.(notifications.length) : badgeNavigator.clearAppBadge?.(),
            )
            .catch(() => undefined);
    };

    document.addEventListener('visibilitychange', sync);
    globalThis.addEventListener('focus', sync);
    sync();
}
