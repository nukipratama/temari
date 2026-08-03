import { router } from '@inertiajs/react';
import { useCallback, useRef, useState } from 'react';

import { useDemoGuard } from '@/hooks/useDemoGuard';

export interface NotificationPrefs {
    /** Master switch over everything Temari sends, on every channel. */
    notifications_enabled: boolean;
    /** Per-channel mutes: off means wired but silent, not disconnected. */
    telegram_enabled: boolean;
    push_enabled: boolean;
}

interface UseNotificationPrefsArgs {
    prefs: NotificationPrefs;
}

/**
 * Owns the three notification-preference toggles: local state (so a rapid
 * flip of two toggles before Inertia refreshes props can't read stale values
 * and revert one of them), the demo write-guard, and the PATCH that always
 * sends the complete state (the server requires all three, and a partial
 * write would leave updateOrCreate holding stale values for the other group).
 */
export function useNotificationPrefs({ prefs }: UseNotificationPrefsArgs) {
    const [notificationsEnabled, setNotificationsEnabled] = useState(
        prefs.notifications_enabled,
    );
    const [telegramEnabled, setTelegramEnabled] = useState(
        prefs.telegram_enabled,
    );
    const [pushEnabled, setPushEnabled] = useState(prefs.push_enabled);
    const { open, setOpen, guard } = useDemoGuard();

    const latestRef = useRef({
        notificationsEnabled,
        telegramEnabled,
        pushEnabled,
    });
    latestRef.current = { notificationsEnabled, telegramEnabled, pushEnabled };

    const savePrefs = useCallback(() => {
        const current = latestRef.current;
        router.patch(
            '/profil/notifikasi',
            {
                notifications_enabled: current.notificationsEnabled,
                telegram_enabled: current.telegramEnabled,
                push_enabled: current.pushEnabled,
            },
            { preserveScroll: true },
        );
    }, []);

    const updateNotificationsEnabled = useCallback(
        (value: boolean) => {
            guard(() => {
                setNotificationsEnabled(value);
                latestRef.current.notificationsEnabled = value;
                savePrefs();
            });
        },
        [guard, savePrefs],
    );

    const updateTelegramEnabled = useCallback(
        (value: boolean) => {
            guard(() => {
                setTelegramEnabled(value);
                latestRef.current.telegramEnabled = value;
                savePrefs();
            });
        },
        [guard, savePrefs],
    );

    const updatePushEnabled = useCallback(
        (value: boolean) => {
            guard(() => {
                setPushEnabled(value);
                latestRef.current.pushEnabled = value;
                savePrefs();
            });
        },
        [guard, savePrefs],
    );

    return {
        notificationsEnabled,
        telegramEnabled,
        pushEnabled,
        setNotificationsEnabled: updateNotificationsEnabled,
        setTelegramEnabled: updateTelegramEnabled,
        setPushEnabled: updatePushEnabled,
        guard,
        demoModalOpen: open,
        setDemoModalOpen: setOpen,
    };
}
