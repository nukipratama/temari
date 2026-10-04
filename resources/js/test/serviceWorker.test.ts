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
        listeners.push({
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
