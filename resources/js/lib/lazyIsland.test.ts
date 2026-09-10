import { render, screen } from '@testing-library/react';
import { createElement, Suspense } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { lazyIsland, recoverFromChunkFailure } from './lazyIsland';

const reload = vi.fn();

beforeEach(() => {
    reload.mockClear();
    window.sessionStorage.clear();
    Object.defineProperty(window, 'location', {
        configurable: true,
        value: { ...window.location, reload },
    });
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
});
