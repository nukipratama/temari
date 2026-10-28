import { readFileSync } from 'node:fs';
import path from 'node:path';
import { describe, expect, it, vi } from 'vitest';

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
    const setAppBadge = vi.fn(() => Promise.resolve());
    const clearAppBadge = vi.fn(() => Promise.resolve());
    const openWindow = vi.fn(() => Promise.resolve(null));
    const worker = {
        addEventListener: (type: string, listener: Listener) => {
            listeners[type] = listener;
        },
        registration: {
            showNotification,
            getNotifications: () => Promise.resolve([{}, {}]),
        },
        navigator: { setAppBadge, clearAppBadge },
        location: { origin: 'https://temari.test' },
        clients: {
            matchAll: () => Promise.resolve([]),
            openWindow,
        },
    };
    new Function('self', 'caches', source)(worker, {});

    async function dispatch(type: string, event: object): Promise<void> {
        let pending: Promise<unknown> = Promise.resolve();
        listeners[type]?.({
            ...event,
            waitUntil: (promise: Promise<unknown>) => {
                pending = promise;
            },
        });
        await pending;
    }

    function click(data: unknown): Promise<void> {
        return dispatch('notificationclick', {
            notification: { data, close: vi.fn() },
        });
    }

    function push(data: PushData): Promise<void> {
        return dispatch('push', { data });
    }

    return {
        push,
        click,
        dispatch,
        showNotification,
        setAppBadge,
        clearAppBadge,
        openWindow,
    };
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

describe('service worker badge', () => {
    it('sets the badge to the unread count a push carries', async () => {
        const { push, setAppBadge } = loadWorker();

        await push(jsonData({ title: 'Hi', data: { unread: 3 } }));

        expect(setAppBadge).toHaveBeenCalledWith(3);
    });

    it('clears the badge when a push carries zero unread', async () => {
        const { push, clearAppBadge, setAppBadge } = loadWorker();

        await push(jsonData({ title: 'Hi', data: { unread: 0 } }));

        expect(clearAppBadge).toHaveBeenCalledOnce();
        expect(setAppBadge).not.toHaveBeenCalled();
    });

    it('leaves the badge alone when a push carries no count, never counting the tray', async () => {
        const { push, clearAppBadge, setAppBadge } = loadWorker();

        await push(jsonData({ title: 'Hi', data: { url: '/' } }));

        expect(setAppBadge).not.toHaveBeenCalled();
        expect(clearAppBadge).not.toHaveBeenCalled();
    });

    it('leaves the badge alone when a notification is tapped or dismissed', async () => {
        const { push, click, dispatch, setAppBadge, clearAppBadge } =
            loadWorker();
        await push(jsonData({ title: 'Hi', data: { unread: 2 } }));
        setAppBadge.mockClear();

        await click({ url: '/' });
        await dispatch('notificationclose', { notification: {} });

        expect(setAppBadge).not.toHaveBeenCalled();
        expect(clearAppBadge).not.toHaveBeenCalled();
    });
});

describe('service worker notification tap', () => {
    it('opens the push url marked with the inbox row to read', async () => {
        const { click, openWindow } = loadWorker();

        await click({ url: '/race?tab=goal', inbox_id: 42 });

        expect(openWindow).toHaveBeenCalledWith(
            'https://temari.test/race?tab=goal&read=42',
        );
    });

    it('opens the push url unchanged when it names no inbox row', async () => {
        const { click, openWindow } = loadWorker();

        await click({ url: '/dashboard' });

        expect(openWindow).toHaveBeenCalledWith(
            'https://temari.test/dashboard',
        );
    });

    it('never follows an off-origin url', async () => {
        const { click, openWindow } = loadWorker();

        await click({ url: 'https://evil.example/x', inbox_id: 1 });

        expect(openWindow).toHaveBeenCalledWith('/');
    });
});
