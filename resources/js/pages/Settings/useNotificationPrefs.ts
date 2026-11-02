import { router } from '@inertiajs/react';
import { useCallback, useRef, useState } from 'react';

import { useDemoGuard } from '@/hooks/useDemoGuard';

export interface NotificationPrefs {
    /** Master switch over everything Temari sends, on every channel. */
    notifications_enabled: boolean;
    /** The morning briefing's own switch, under the master one. */
    briefing_push_enabled: boolean;
    /** Minutes since local midnight to send the briefing at; null sends it an hour before the usual run. */
    briefing_push_minute: number | null;
    /** Per-channel mutes: off means wired but silent, not disconnected. */
    telegram_enabled: boolean;
    push_enabled: boolean;
}

interface UseNotificationPrefsArgs {
    prefs: NotificationPrefs;
}

/**
 * Owns the notification preferences: local state (so a rapid
 * flip of two toggles before Inertia refreshes props can't read stale values
 * and revert one of them), the demo write-guard, and the PATCH that always
 * sends the complete state (the server requires every field, and a partial
 * write would leave updateOrCreate holding stale values for the others).
 */
export function useNotificationPrefs({ prefs }: UseNotificationPrefsArgs) {
    const [notificationsEnabled, setNotificationsEnabled] = useState(
        prefs.notifications_enabled,
    );
    const [briefingPushEnabled, setBriefingPushEnabled] = useState(
        prefs.briefing_push_enabled,
    );
    const [briefingPushMinute, setBriefingPushMinute] = useState(
        prefs.briefing_push_minute,
    );
    const [telegramEnabled, setTelegramEnabled] = useState(
        prefs.telegram_enabled,
    );
    const [pushEnabled, setPushEnabled] = useState(prefs.push_enabled);
    const { open, setOpen, guard } = useDemoGuard();

    const latestRef = useRef({
        notificationsEnabled,
        briefingPushEnabled,
        briefingPushMinute,
        telegramEnabled,
        pushEnabled,
    });

    const savePrefs = useCallback(() => {
        const current = latestRef.current;
        router.patch(
            '/profile/notifications',
            {
                notifications_enabled: current.notificationsEnabled,
                briefing_push_enabled: current.briefingPushEnabled,
                briefing_push_minute: current.briefingPushMinute,
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

    const updateBriefingPushEnabled = useCallback(
        (value: boolean) => {
            guard(() => {
                setBriefingPushEnabled(value);
                latestRef.current.briefingPushEnabled = value;
                savePrefs();
            });
        },
        [guard, savePrefs],
    );

    const updateBriefingPushMinute = useCallback(
        (value: number | null) => {
            guard(() => {
                setBriefingPushMinute(value);
                latestRef.current.briefingPushMinute = value;
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
        briefingPushEnabled,
        briefingPushMinute,
        telegramEnabled,
        pushEnabled,
        setNotificationsEnabled: updateNotificationsEnabled,
        setBriefingPushEnabled: updateBriefingPushEnabled,
        setBriefingPushMinute: updateBriefingPushMinute,
        setTelegramEnabled: updateTelegramEnabled,
        setPushEnabled: updatePushEnabled,
        guard,
        demoModalOpen: open,
        setDemoModalOpen: setOpen,
    };
}
