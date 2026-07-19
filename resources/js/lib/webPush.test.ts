import { afterEach, describe, expect, it, vi } from 'vitest';
import {
    currentSubscription,
    isIosNonSafari,
    isPushSupported,
    isStandalone,
    subscribe,
    unsubscribe,
    urlBase64ToUint8Array,
} from './webPush';

vi.mock('@/lib/http', () => ({ csrfToken: () => 'test-csrf' }));

const fakeSubscription = {
    endpoint: 'https://fcm.googleapis.com/fcm/send/abc',
    toJSON: () => ({ endpoint: 'https://fcm.googleapis.com/fcm/send/abc', keys: { p256dh: 'k', auth: 't' } }),
    unsubscribe: vi.fn(() => Promise.resolve(true)),
};

const fakeRegistration = {
    pushManager: {
        subscribe: vi.fn(() => Promise.resolve(fakeSubscription)),
        getSubscription: vi.fn(() => Promise.resolve(fakeSubscription)),
    },
};

function stubServiceWorker(): void {
    Object.defineProperty(navigator, 'serviceWorker', {
        configurable: true,
        value: {
            register: vi.fn(() => Promise.resolve(fakeRegistration)),
            getRegistration: vi.fn(() => Promise.resolve(fakeRegistration)),
            ready: Promise.resolve(fakeRegistration),
        },
    });
    vi.stubGlobal('PushManager', function PushManager() {});
    vi.stubGlobal('Notification', { permission: 'default', requestPermission: vi.fn(() => Promise.resolve('granted')) });
    vi.stubGlobal('fetch', vi.fn(() => Promise.resolve({ ok: true })));
}

afterEach(() => {
    vi.unstubAllGlobals();
    Reflect.deleteProperty(navigator, 'serviceWorker');
    vi.clearAllMocks();
});

describe('urlBase64ToUint8Array', () => {
    it('decodes a base64url VAPID key to its bytes', () => {
        // "hello" → base64url "aGVsbG8"
        expect([...urlBase64ToUint8Array('aGVsbG8')]).toEqual([...new TextEncoder().encode('hello')]);
    });

    it('handles the -/_ base64url alphabet', () => {
        // bytes [251, 255] → base64 "+/8=" → base64url "-_8"
        expect([...urlBase64ToUint8Array('-_8')]).toEqual([251, 255]);
    });
});

describe('capability detection', () => {
    it('reports push support when the APIs are present', () => {
        stubServiceWorker();
        expect(isPushSupported()).toBe(true);
    });

    it('reports no push support without a service worker', () => {
        expect(isPushSupported()).toBe(false);
    });

    it('detects standalone display mode', () => {
        vi.stubGlobal('matchMedia', () => ({ matches: true }));
        expect(isStandalone()).toBe(true);
    });

    it('flags an iOS non-Safari browser', () => {
        vi.stubGlobal('navigator', { userAgent: 'Mozilla/5.0 (iPhone) CriOS/120' });
        expect(isIosNonSafari()).toBe(true);
    });

    it('does not flag Safari on iOS', () => {
        vi.stubGlobal('navigator', { userAgent: 'Mozilla/5.0 (iPhone) Safari/605' });
        expect(isIosNonSafari()).toBe(false);
    });
});

describe('subscribe', () => {
    it('registers, subscribes, and posts the subscription', async () => {
        stubServiceWorker();

        await subscribe('aGVsbG8');

        expect(navigator.serviceWorker.register).toHaveBeenCalledWith('/sw.js');
        expect(fetch).toHaveBeenCalledWith('/profil/push', expect.objectContaining({ method: 'POST' }));
    });

    it('throws permission-denied when the prompt is blocked', async () => {
        stubServiceWorker();
        (Notification.requestPermission as ReturnType<typeof vi.fn>).mockResolvedValue('denied');

        await expect(subscribe('aGVsbG8')).rejects.toThrow('permission-denied');
        expect(fetch).not.toHaveBeenCalled();
    });
});

describe('unsubscribe', () => {
    it('drops the local subscription and removes it server-side', async () => {
        stubServiceWorker();

        await unsubscribe();

        expect(fakeSubscription.unsubscribe).toHaveBeenCalled();
        expect(fetch).toHaveBeenCalledWith('/profil/push', expect.objectContaining({ method: 'DELETE' }));
    });
});

describe('currentSubscription', () => {
    it('returns null when push is unsupported', async () => {
        expect(await currentSubscription()).toBeNull();
    });

    it('returns the live subscription when present', async () => {
        stubServiceWorker();
        expect(await currentSubscription()).toBe(fakeSubscription);
    });
});
