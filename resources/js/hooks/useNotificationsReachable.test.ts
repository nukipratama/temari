import { renderHook } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { useNotificationsReachable } from './useNotificationsReachable';
import { setMockPage } from '@/test/setup';

describe('useNotificationsReachable', () => {
    it('is false when no channel is wired', () => {
        setMockPage({ telegramConnected: false, webPushSubscribed: false });
        expect(renderHook(() => useNotificationsReachable()).result.current).toBe(false);
    });

    it('is true when only Telegram is connected', () => {
        setMockPage({ telegramConnected: true, webPushSubscribed: false });
        expect(renderHook(() => useNotificationsReachable()).result.current).toBe(true);
    });

    // The reason this hook exists: a push-only user used to see a dead button
    // because the gate read `telegramConnected` alone.
    it('is true when only web push is subscribed', () => {
        setMockPage({ telegramConnected: false, webPushSubscribed: true });
        expect(renderHook(() => useNotificationsReachable()).result.current).toBe(true);
    });

    it('is true when both channels are wired', () => {
        setMockPage({ telegramConnected: true, webPushSubscribed: true });
        expect(renderHook(() => useNotificationsReachable()).result.current).toBe(true);
    });

    it('treats missing props as unreachable rather than throwing', () => {
        setMockPage({});
        expect(renderHook(() => useNotificationsReachable()).result.current).toBe(false);
    });
});
