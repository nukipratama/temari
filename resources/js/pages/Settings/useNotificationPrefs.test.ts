import { router } from '@inertiajs/react';
import { act, renderHook } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import { makeUser, setMockPage } from '@/test/setup';

import {
    useNotificationPrefs,
    type NotificationPrefs,
} from './useNotificationPrefs';

const PREFS: NotificationPrefs = {
    notifications_enabled: false,
    briefing_push_enabled: true,
    briefing_push_minute: null,
    telegram_enabled: true,
    push_enabled: true,
};

beforeEach(() => {
    setMockPage({
        auth: { user: makeUser() },
        flash: {},
        demoLoginEnabled: false,
    });
});

describe('useNotificationPrefs', () => {
    it('starts from the given prefs', () => {
        const { result } = renderHook(() =>
            useNotificationPrefs({ prefs: PREFS }),
        );

        expect(result.current.notificationsEnabled).toBe(false);
        expect(result.current.telegramEnabled).toBe(true);
        expect(result.current.pushEnabled).toBe(true);
        expect(result.current.briefingPushEnabled).toBe(true);
        expect(result.current.briefingPushMinute).toBeNull();
    });

    it('flips a toggle locally and PATCHes the complete state', () => {
        vi.mocked(router.patch).mockReset();
        const { result } = renderHook(() =>
            useNotificationPrefs({ prefs: PREFS }),
        );

        act(() => result.current.setNotificationsEnabled(true));

        expect(result.current.notificationsEnabled).toBe(true);
        expect(router.patch).toHaveBeenCalledWith(
            '/profile/notifications',
            {
                notifications_enabled: true,
                briefing_push_enabled: true,
                briefing_push_minute: null,
                telegram_enabled: true,
                push_enabled: true,
            },
            { preserveScroll: true },
        );
    });

    it('carries the latest value of every axis, not just the one that changed', () => {
        vi.mocked(router.patch).mockReset();
        const { result } = renderHook(() =>
            useNotificationPrefs({ prefs: PREFS }),
        );

        act(() => result.current.setTelegramEnabled(false));
        act(() => result.current.setPushEnabled(false));

        expect(router.patch).toHaveBeenLastCalledWith(
            '/profile/notifications',
            {
                notifications_enabled: false,
                briefing_push_enabled: true,
                briefing_push_minute: null,
                telegram_enabled: false,
                push_enabled: false,
            },
            { preserveScroll: true },
        );
    });

    it('carries both flips when two toggles change before a re-render', () => {
        vi.mocked(router.patch).mockReset();
        const { result } = renderHook(() =>
            useNotificationPrefs({ prefs: PREFS }),
        );

        act(() => {
            result.current.setTelegramEnabled(false);
            result.current.setPushEnabled(false);
        });

        expect(router.patch).toHaveBeenLastCalledWith(
            '/profile/notifications',
            {
                notifications_enabled: false,
                briefing_push_enabled: true,
                briefing_push_minute: null,
                telegram_enabled: false,
                push_enabled: false,
            },
            { preserveScroll: true },
        );
    });

    it('PATCHes the briefing switch and send time with the rest of the state', () => {
        vi.mocked(router.patch).mockReset();
        const { result } = renderHook(() =>
            useNotificationPrefs({ prefs: PREFS }),
        );

        act(() => result.current.setBriefingPushMinute(5 * 60 + 30));
        act(() => result.current.setBriefingPushEnabled(false));

        expect(result.current.briefingPushMinute).toBe(330);
        expect(router.patch).toHaveBeenLastCalledWith(
            '/profile/notifications',
            {
                notifications_enabled: false,
                briefing_push_enabled: false,
                briefing_push_minute: 330,
                telegram_enabled: true,
                push_enabled: true,
            },
            { preserveScroll: true },
        );
    });

    it('opens the demo-blocked modal instead of patching for a demo user', () => {
        setMockPage({
            auth: { user: makeUser({ is_demo: true }) },
            flash: {},
            demoLoginEnabled: false,
        });
        vi.mocked(router.patch).mockReset();
        const { result } = renderHook(() =>
            useNotificationPrefs({ prefs: PREFS }),
        );

        act(() => result.current.setNotificationsEnabled(true));

        expect(router.patch).not.toHaveBeenCalled();
        expect(result.current.notificationsEnabled).toBe(false);
        expect(result.current.demoModalOpen).toBe(true);
    });

    it('closes the demo-blocked modal via setDemoModalOpen', () => {
        setMockPage({
            auth: { user: makeUser({ is_demo: true }) },
            flash: {},
            demoLoginEnabled: false,
        });
        const { result } = renderHook(() =>
            useNotificationPrefs({ prefs: PREFS }),
        );

        act(() => result.current.setNotificationsEnabled(true));
        expect(result.current.demoModalOpen).toBe(true);

        act(() => result.current.setDemoModalOpen(false));
        expect(result.current.demoModalOpen).toBe(false);
    });
});
