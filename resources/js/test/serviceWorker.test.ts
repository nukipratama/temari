import { readFileSync } from 'node:fs';
import path from 'node:path';
import { describe, expect, it, vi } from 'vitest';

import { defined } from '@/test/defined';

const source = readFileSync(
    path.resolve(__dirname, '../../../public/sw.js'),
    'utf8',
);

type PushData = { json: () => unknown; text: () => string } | null;
type Listener = (event: unknown) => void;

function loadWorker() {
    const listeners: Record<string, Listener> = {};
    const showNotification = vi.fn((title: string, options: unknown) =>
        Promise.resolve({ title, options }),
    );
    const worker = {
        addEventListener: (type: string, listener: Listener) => {
            listeners[type] = listener;
        },
        registration: {
            showNotification,
            getNotifications: () => Promise.resolve([]),
        },
        navigator: {},
    };
    new Function('self', 'caches', source)(worker, {});

    async function push(data: PushData): Promise<void> {
        let pending: Promise<unknown> = Promise.resolve();
        defined(listeners.push)({
            data,
            waitUntil: (promise: Promise<unknown>) => {
                pending = promise;
            },
        });
        await pending;
    }

    return { push, showNotification };
}

function jsonData(value: unknown): PushData {
    return { json: () => value, text: () => JSON.stringify(value) };
}

const fallback = [
    'Temari',
    expect.objectContaining({
        body: 'Something new is in your inbox.',
        data: { url: '/inbox' },
    }),
];

describe('service worker push', () => {
    it('shows the payload it was sent', async () => {
        const { push, showNotification } = loadWorker();

        await push(
            jsonData({
                title: 'Your 8.2K run is in.',
                body: 'steady.',
                data: { url: '/activities/1', unread: 2 },
            }),
        );

        expect(showNotification).toHaveBeenCalledWith(
            'Your 8.2K run is in.',
            expect.objectContaining({
                body: 'steady.',
                data: { url: '/activities/1', unread: 2 },
            }),
        );
    });

    it('shows plain text when the payload is not JSON', async () => {
        const { push, showNotification } = loadWorker();

        await push({
            json: () => {
                throw new SyntaxError('not json');
            },
            text: () => 'hello',
        });

        expect(showNotification).toHaveBeenCalledWith(
            'Temari',
            expect.objectContaining({ body: 'hello' }),
        );
    });

    it.each<[string, PushData]>([
        ['no data', null],
        ['a JSON null', jsonData(null)],
        ['an empty object', jsonData({})],
        [
            'an empty text body',
            {
                json: () => {
                    throw new SyntaxError('empty');
                },
                text: () => '',
            },
        ],
        [
            'unreadable data',
            {
                json: () => {
                    throw new SyntaxError('bad');
                },
                text: () => {
                    throw new TypeError('bad');
                },
            },
        ],
    ])('still shows a notification for %s', async (_, data) => {
        const { push, showNotification } = loadWorker();

        await push(data);

        expect(showNotification).toHaveBeenCalledOnce();
        expect(showNotification).toHaveBeenCalledWith(...fallback);
    });
});

function loadNavigationWorker(cachedOffline: Response | undefined) {
    const listeners: Record<string, Listener> = {};
    const enable = vi.fn(() => Promise.resolve());
    const worker = {
        addEventListener: (type: string, listener: Listener) => {
            listeners[type] = listener;
        },
        registration: { navigationPreload: { enable } },
        clients: { claim: () => Promise.resolve() },
        skipWaiting: () => Promise.resolve(),
    };
    const caches = {
        keys: () => Promise.resolve([]),
        match: () => Promise.resolve(cachedOffline),
    };
    new Function('self', 'caches', source)(worker, caches);

    async function activate(): Promise<void> {
        let pending: Promise<unknown> = Promise.resolve();
        defined(listeners.activate)({
            waitUntil: (promise: Promise<unknown>) => {
                pending = promise;
            },
        });
        await pending;
    }

    async function navigate(
        preloadResponse: Promise<Response | undefined>,
    ): Promise<Response> {
        let responded: Promise<Response> | undefined;
        defined(listeners.fetch)({
            request: { mode: 'navigate' },
            preloadResponse,
            respondWith: (promise: Promise<Response>) => {
                responded = promise;
            },
        });
        return defined(responded);
    }

    return { activate, navigate, enable };
}

describe('service worker navigation', () => {
    it('turns on navigation preload when it activates', async () => {
        const { activate, enable } = loadNavigationWorker(undefined);

        await activate();

        expect(enable).toHaveBeenCalledOnce();
    });

    it('answers a navigation with the preloaded response', async () => {
        const fetchSpy = vi.fn();
        vi.stubGlobal('fetch', fetchSpy);
        const { navigate } = loadNavigationWorker(undefined);
        const preloaded = new Response('preloaded');

        const response = await navigate(Promise.resolve(preloaded));

        expect(response).toBe(preloaded);
        expect(fetchSpy).not.toHaveBeenCalled();
        vi.unstubAllGlobals();
    });

    it('fetches the navigation itself when nothing was preloaded', async () => {
        const fetched = new Response('fetched');
        vi.stubGlobal(
            'fetch',
            vi.fn(() => Promise.resolve(fetched)),
        );
        const { navigate } = loadNavigationWorker(undefined);

        const response = await navigate(Promise.resolve(undefined));

        expect(response).toBe(fetched);
        vi.unstubAllGlobals();
    });

    it('falls back to the offline page when the network fails', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(() => Promise.reject(new TypeError('offline'))),
        );
        const offline = new Response('offline');
        const { navigate } = loadNavigationWorker(offline);

        const response = await navigate(
            Promise.reject(new TypeError('offline')),
        );

        expect(response).toBe(offline);
        vi.unstubAllGlobals();
    });
});
