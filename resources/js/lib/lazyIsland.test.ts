import { render, screen } from '@testing-library/react';
import { createElement, startTransition, Suspense, useEffect } from 'react';
import { createRoot } from 'react-dom/client';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { reloadDocument } from '@/lib/reloadDocument';

import { lazyIsland, recoverFromChunkFailure } from './lazyIsland';

vi.mock('@/lib/reloadDocument', () => ({ reloadDocument: vi.fn() }));

const reload = vi.mocked(reloadDocument);

beforeEach(() => {
    reload.mockClear();
    window.sessionStorage.clear();
});

afterEach(() => {
    vi.restoreAllMocks();
});

describe('recoverFromChunkFailure', () => {
    it('reloads the document once, then refuses to loop', () => {
        expect(recoverFromChunkFailure()).toBe(true);
        expect(reload).toHaveBeenCalledTimes(1);

        expect(recoverFromChunkFailure()).toBe(false);
        expect(reload).toHaveBeenCalledTimes(1);
    });

    it('leaves the reload alone when session storage is unavailable', () => {
        vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
            throw new Error('denied');
        });

        expect(recoverFromChunkFailure()).toBe(false);
        expect(reload).not.toHaveBeenCalled();
    });
});

describe('lazyIsland', () => {
    it('reloads once when the chunk fails to load, without reaching the boundary', async () => {
        const Island = lazyIsland(() =>
            Promise.reject(
                new Error('Failed to fetch dynamically imported module'),
            ),
        );

        render(
            createElement(
                Suspense,
                { fallback: createElement('p', null, 'holding') },
                createElement(Island),
            ),
        );

        await vi.waitFor(() => expect(reload).toHaveBeenCalledTimes(1));
        // The rejection never propagates: the fallback is still on screen
        // while the document is replaced.
        expect(screen.getByText('holding')).toBeInTheDocument();
    });

    it('renders the module when the chunk loads', async () => {
        const Island = lazyIsland(() =>
            Promise.resolve({
                default: () => createElement('p', null, 'arrived'),
            }),
        );

        render(
            createElement(Suspense, { fallback: null }, createElement(Island)),
        );

        expect(await screen.findByText('arrived')).toBeInTheDocument();
        expect(reload).not.toHaveBeenCalled();
    });

    it('falls back while the chunk is cold', async () => {
        const Island = lazyIsland(() =>
            Promise.resolve({
                default: () => createElement('p', null, 'arrived'),
            }),
        );

        render(
            createElement(
                Suspense,
                { fallback: createElement('p', null, 'holding') },
                createElement(Island),
            ),
        );

        expect(screen.getByText('holding')).toBeInTheDocument();
        expect(await screen.findByText('arrived')).toBeInTheDocument();
    });

    it('renders without suspending once the chunk is preloaded', async () => {
        const load = vi.fn(() =>
            Promise.resolve({
                default: (props: { label: string }) =>
                    createElement('p', null, props.label),
            }),
        );
        const Island = lazyIsland(load);

        Island.preload();
        await vi.waitFor(() => expect(load).toHaveBeenCalledTimes(1));
        await Promise.resolve();

        render(
            createElement(
                Suspense,
                { fallback: createElement('p', null, 'holding') },
                createElement(Island, { label: 'arrived' }),
            ),
        );

        expect(screen.getByText('arrived')).toBeInTheDocument();
        expect(screen.queryByText('holding')).not.toBeInTheDocument();
    });

    it('keeps a cold-mounted island mounted when its props change', async () => {
        let mounts = 0;
        function Counted(props: { label: string }) {
            useEffect(() => {
                mounts += 1;
            }, []);

            return createElement('p', null, props.label);
        }
        const Island = lazyIsland(() => Promise.resolve({ default: Counted }));
        const tree = (label: string) =>
            createElement(
                Suspense,
                { fallback: null },
                createElement(Island, { label }),
            );

        const actEnvironment = Reflect.get(
            globalThis,
            'IS_REACT_ACT_ENVIRONMENT',
        );
        Reflect.set(globalThis, 'IS_REACT_ACT_ENVIRONMENT', false);
        const root = createRoot(
            document.body.appendChild(document.createElement('div')),
        );

        try {
            startTransition(() => root.render(tree('first')));
            await screen.findByText('first');
            root.render(tree('second'));
            await screen.findByText('second');

            expect(mounts).toBe(1);
        } finally {
            root.unmount();
            Reflect.set(globalThis, 'IS_REACT_ACT_ENVIRONMENT', actEnvironment);
        }
    });

    it('survives a failed preload and still loads on first render', async () => {
        const load = vi
            .fn()
            .mockRejectedValueOnce(new Error('offline'))
            .mockResolvedValue({
                default: () => createElement('p', null, 'arrived'),
            });
        const Island = lazyIsland(load);

        Island.preload();
        await vi.waitFor(() => expect(load).toHaveBeenCalledTimes(1));

        render(
            createElement(Suspense, { fallback: null }, createElement(Island)),
        );

        expect(await screen.findByText('arrived')).toBeInTheDocument();
        expect(reload).not.toHaveBeenCalled();
    });

    it('still reloads when the first render fails after a failed preload', async () => {
        const Island = lazyIsland(() =>
            Promise.reject(
                new Error('Failed to fetch dynamically imported module'),
            ),
        );

        Island.preload();
        render(
            createElement(Suspense, { fallback: null }, createElement(Island)),
        );

        await vi.waitFor(() => expect(reload).toHaveBeenCalledTimes(1));
    });
});
