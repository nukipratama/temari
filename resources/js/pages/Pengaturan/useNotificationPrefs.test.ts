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
    });

    it('flips a toggle locally and PATCHes the complete state', () => {
        vi.mocked(router.patch).mockReset();
        const { result } = renderHook(() =>
            useNotificationPrefs({ prefs: PREFS }),
        );

        act(() => result.current.setNotificationsEnabled(true));

        expect(result.current.notificationsEnabled).toBe(true);
        expect(router.patch).toHaveBeenCalledWith(
            '/profil/notifikasi',
            {
                notifications_enabled: true,
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
            '/profil/notifikasi',
            {
                notifications_enabled: false,
                telegram_enabled: false,
                push_enabled: false,
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
