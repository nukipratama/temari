import { afterEach, describe, expect, it, vi } from 'vitest';

import {
    currentSubscription,
    isIos,
    isIosNonSafari,
    isPushSupported,
    isStandalone,
    releaseDeviceSubscription,
    reportSeen,
    subscribe,
    unsubscribe,
    urlBase64ToUint8Array,
} from './webPush';

vi.mock('@/lib/http', () => ({ csrfToken: () => 'test-csrf' }));

const fakeSubscription = {
    endpoint: 'https://fcm.googleapis.com/fcm/send/abc',
    toJSON: () => ({
        endpoint: 'https://fcm.googleapis.com/fcm/send/abc',
        keys: { p256dh: 'k', auth: 't' },
    }),
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
    vi.stubGlobal('Notification', {
        permission: 'default',
        requestPermission: vi.fn(() => Promise.resolve('granted')),
    });
    vi.stubGlobal(
        'fetch',
        vi.fn(() => Promise.resolve({ ok: true })),
    );
}

afterEach(() => {
    vi.unstubAllGlobals();
    Reflect.deleteProperty(navigator, 'serviceWorker');
    vi.clearAllMocks();
    localStorage.clear();
});

describe('urlBase64ToUint8Array', () => {
    it('decodes a base64url VAPID key to its bytes', () => {
        // "hello" → base64url "aGVsbG8"
        expect([...urlBase64ToUint8Array('aGVsbG8')]).toEqual([
            ...new TextEncoder().encode('hello'),
        ]);
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
        vi.stubGlobal('navigator', {
            userAgent: 'Mozilla/5.0 (iPhone) CriOS/120',
        });
        expect(isIosNonSafari()).toBe(true);
    });

    it('does not flag Safari on iOS', () => {
        vi.stubGlobal('navigator', {
            userAgent: 'Mozilla/5.0 (iPhone) Safari/605',
        });
        expect(isIosNonSafari()).toBe(false);
    });
});

describe('isIos', () => {
    it.each([
        [
            'iPhone Safari',
            'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0) Safari/605',
            'iPhone',
            5,
            true,
        ],
        ['iPhone Chrome', 'Mozilla/5.0 (iPhone) CriOS/120', 'iPhone', 5, true],
        [
            'iPad Safari with a mobile UA',
            'Mozilla/5.0 (iPad; CPU OS 18_0) Safari/605',
            'iPad',
            5,
            true,
        ],
        [
            'iPadOS Safari posing as a Mac',
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Safari/605',
            'MacIntel',
            5,
            true,
        ],
        [
            'a Mac without touch',
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Safari/605',
            'MacIntel',
            0,
            false,
        ],
        [
            'Android Chrome',
            'Mozilla/5.0 (Linux; Android 15) Chrome/130 Mobile',
            'Linux armv81',
            5,
            false,
        ],
        [
            'Windows Chrome',
            'Mozilla/5.0 (Windows NT 10.0) Chrome/130',
            'Win32',
            0,
            false,
        ],
        [
            'Windows Firefox',
            'Mozilla/5.0 (Windows NT 10.0; rv:130.0) Firefox/130.0',
            'Win32',
            0,
            false,
        ],
    ])('%s', (_name, userAgent, platform, maxTouchPoints, expected) => {
        vi.stubGlobal('navigator', { userAgent, platform, maxTouchPoints });
        expect(isIos()).toBe(expected);
    });
});

describe('subscribe', () => {
    it('registers, subscribes, and posts the subscription', async () => {
        stubServiceWorker();

        await subscribe('aGVsbG8');

        expect(navigator.serviceWorker.register).toHaveBeenCalledWith('/sw.js');
        expect(fetch).toHaveBeenCalledWith(
            '/profile/push',
            expect.objectContaining({ method: 'POST' }),
        );
    });

    it('throws permission-denied when the prompt is blocked', async () => {
        stubServiceWorker();
        (
            Notification.requestPermission as ReturnType<typeof vi.fn>
        ).mockResolvedValue('denied');

        await expect(subscribe('aGVsbG8')).rejects.toThrow('permission-denied');
        expect(fetch).not.toHaveBeenCalled();
    });

    it('sends one request for a double tap and a fresh one afterwards', async () => {
        stubServiceWorker();

        await Promise.all([subscribe('aGVsbG8'), subscribe('aGVsbG8')]);
        expect(fetch).toHaveBeenCalledTimes(1);

        await subscribe('aGVsbG8');
        expect(fetch).toHaveBeenCalledTimes(2);
    });
});

describe('unsubscribe', () => {
    it('drops the local subscription and removes it server-side', async () => {
        stubServiceWorker();

        await unsubscribe();

        expect(fakeSubscription.unsubscribe).toHaveBeenCalled();
        expect(fetch).toHaveBeenCalledWith(
            '/profile/push',
            expect.objectContaining({ method: 'DELETE' }),
        );
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

describe('replacing the subscription this device saved before', () => {
    function postedBody(): Record<string, unknown> {
        const [, init] = (fetch as ReturnType<typeof vi.fn>).mock.calls[0] as [
            string,
            RequestInit,
        ];
        return JSON.parse(init.body as string) as Record<string, unknown>;
    }

    it('sends no previous endpoint on a first subscribe', async () => {
        stubServiceWorker();

        await subscribe('aGVsbG8');

        expect(postedBody()).not.toHaveProperty('previous_endpoint');
    });

    it('sends the endpoint it saved before so the server can drop it', async () => {
        stubServiceWorker();
        localStorage.setItem(
            'temari-push-endpoint',
            'https://web.push.apple.com/revoked',
        );

        await subscribe('aGVsbG8');

        expect(postedBody().previous_endpoint).toBe(
            'https://web.push.apple.com/revoked',
        );
    });

    it('remembers the endpoint once the server stored it', async () => {
        stubServiceWorker();

        await subscribe('aGVsbG8');

        expect(localStorage.getItem('temari-push-endpoint')).toBe(
            fakeSubscription.endpoint,
        );
    });

    it('still subscribes when storage is unavailable', async () => {
        stubServiceWorker();
        const blocked = () => {
            throw new Error('SecurityError');
        };
        vi.spyOn(Storage.prototype, 'getItem').mockImplementation(blocked);
        vi.spyOn(Storage.prototype, 'setItem').mockImplementation(blocked);

        await expect(subscribe('aGVsbG8')).resolves.toBeUndefined();
        expect(postedBody()).not.toHaveProperty('previous_endpoint');

        vi.restoreAllMocks();
    });

    it('forgets the endpoint on unsubscribe', async () => {
        stubServiceWorker();
        localStorage.setItem('temari-push-endpoint', fakeSubscription.endpoint);

        await unsubscribe();

        expect(localStorage.getItem('temari-push-endpoint')).toBeNull();
    });
});

describe('releaseDeviceSubscription', () => {
    it('unsubscribes the browser and returns its endpoint', async () => {
        stubServiceWorker();
        localStorage.setItem('temari-push-endpoint', fakeSubscription.endpoint);

        await expect(releaseDeviceSubscription()).resolves.toBe(
            fakeSubscription.endpoint,
        );
        expect(fakeSubscription.unsubscribe).toHaveBeenCalled();
        expect(localStorage.getItem('temari-push-endpoint')).toBeNull();
    });

    it('still returns the endpoint when the browser unsubscribe fails', async () => {
        stubServiceWorker();
        fakeSubscription.unsubscribe.mockImplementationOnce(() =>
            Promise.reject(new Error('boom')),
        );

        await expect(releaseDeviceSubscription()).resolves.toBe(
            fakeSubscription.endpoint,
        );
    });

    it('falls back to the remembered endpoint when the subscription cannot be read', async () => {
        stubServiceWorker();
        localStorage.setItem('temari-push-endpoint', 'https://saved.test/x');
        fakeRegistration.pushManager.getSubscription.mockImplementationOnce(
            () => Promise.reject(new Error('boom')),
        );

        await expect(releaseDeviceSubscription()).resolves.toBe(
            'https://saved.test/x',
        );
    });

    it('returns nothing when this device never subscribed', async () => {
        await expect(releaseDeviceSubscription()).resolves.toBeUndefined();
    });
});

describe('reportSeen', () => {
    const athlete = { auth: { user: { is_demo: false } } };

    function fetchCalls(): [string, RequestInit][] {
        return (fetch as ReturnType<typeof vi.fn>).mock.calls as [
            string,
            RequestInit,
        ][];
    }

    it('reports this device endpoint once a day', async () => {
        stubServiceWorker();

        await reportSeen(athlete);
        await reportSeen(athlete);

        expect(fetchCalls()).toHaveLength(1);
        const [url, init] = fetchCalls()[0];
        expect(url).toBe('/profile/push/seen');
        expect(JSON.parse(init.body as string)).toEqual({
            endpoint: fakeSubscription.endpoint,
        });
    });

    it('sends one request when two visits race', async () => {
        stubServiceWorker();

        await Promise.all([reportSeen(athlete), reportSeen(athlete)]);

        expect(fetchCalls()).toHaveLength(1);
    });

    it('saves the subscription again when the server no longer has it', async () => {
        stubServiceWorker();
        (fetch as ReturnType<typeof vi.fn>).mockResolvedValueOnce({
            ok: false,
            status: 404,
        });

        await reportSeen(athlete);

        expect(fetchCalls().map(([url]) => url)).toEqual([
            '/profile/push/seen',
            '/profile/push',
        ]);
        expect(JSON.parse(fetchCalls()[1][1].body as string)).toMatchObject(
            fakeSubscription.toJSON(),
        );
        expect(localStorage.getItem('temari-push-endpoint')).toBe(
            fakeSubscription.endpoint,
        );
    });

    it('tries again on the next visit when the report failed', async () => {
        stubServiceWorker();
        (fetch as ReturnType<typeof vi.fn>).mockResolvedValueOnce({
            ok: false,
            status: 500,
        });

        await reportSeen(athlete);
        await reportSeen(athlete);

        expect(fetchCalls()).toHaveLength(2);
    });

    it('stays quiet when the request cannot reach the server', async () => {
        stubServiceWorker();
        (fetch as ReturnType<typeof vi.fn>).mockRejectedValueOnce(
            new TypeError('offline'),
        );

        await expect(reportSeen(athlete)).resolves.toBeUndefined();
    });

    it('sends nothing without a push subscription on this device', async () => {
        stubServiceWorker();
        fakeRegistration.pushManager.getSubscription.mockResolvedValueOnce(
            null as never,
        );

        await reportSeen(athlete);

        expect(fetch).not.toHaveBeenCalled();
    });

    it('sends nothing for a guest or the demo account', async () => {
        stubServiceWorker();

        await reportSeen({ auth: { user: null } });
        await reportSeen({ auth: { user: { is_demo: true } } });
        await reportSeen({});

        expect(fetch).not.toHaveBeenCalled();
    });
});
