import type { GlobalEvent } from '@inertiajs/core';

import { router } from '@inertiajs/react';
import { act, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import RouteProgressBar from './RouteProgressBar';

function visitEvent(
    overrides: Partial<{ showProgress: boolean; completed: boolean }> = {},
): GlobalEvent<'start' | 'finish'> {
    return {
        detail: {
            visit: { showProgress: true, completed: true, ...overrides },
        },
    } as unknown as GlobalEvent<'start' | 'finish'>;
}

function registeredCallback(eventName: 'start' | 'finish') {
    const call = vi
        .mocked(router.on)
        .mock.calls.find(([name]) => name === eventName);
    if (!call) {
        throw new Error(`router.on was never called for "${eventName}"`);
    }
    return call[1];
}

describe('RouteProgressBar', () => {
    beforeEach(() => {
        vi.mocked(router.on).mockClear();
    });

    it('renders idle by default', () => {
        render(<RouteProgressBar />);
        expect(screen.getByTestId('route-progress-bar')).toHaveAttribute(
            'data-phase',
            'idle',
        );
    });

    it('enters the loading phase when a full navigation starts', () => {
        render(<RouteProgressBar />);
        act(() => {
            registeredCallback('start')(visitEvent({ showProgress: true }));
        });
        expect(screen.getByTestId('route-progress-bar')).toHaveAttribute(
            'data-phase',
            'loading',
        );
    });

    it('ignores a background/partial reload (showProgress: false)', () => {
        render(<RouteProgressBar />);
        act(() => {
            registeredCallback('start')(visitEvent({ showProgress: false }));
        });
        expect(screen.getByTestId('route-progress-bar')).toHaveAttribute(
            'data-phase',
            'idle',
        );
    });

    it('moves to done when a full navigation completes', () => {
        render(<RouteProgressBar />);
        act(() => {
            registeredCallback('start')(visitEvent({ showProgress: true }));
        });
        act(() => {
            registeredCallback('finish')(
                visitEvent({ showProgress: true, completed: true }),
            );
        });
        expect(screen.getByTestId('route-progress-bar')).toHaveAttribute(
            'data-phase',
            'done',
        );
    });

    it('resets straight to idle when a visit is interrupted or cancelled, not done', () => {
        render(<RouteProgressBar />);
        act(() => {
            registeredCallback('start')(visitEvent({ showProgress: true }));
        });
        act(() => {
            registeredCallback('finish')(
                visitEvent({ showProgress: true, completed: false }),
            );
        });
        // A superseded/cancelled visit still fires `finish`, but it never
        // actually finished — it should not get the "done" fill-and-fade.
        expect(screen.getByTestId('route-progress-bar')).toHaveAttribute(
            'data-phase',
            'idle',
        );
    });

    it('auto-resets to idle once the "done" animation finishes', async () => {
        render(<RouteProgressBar />);
        act(() => {
            registeredCallback('start')(visitEvent({ showProgress: true }));
        });
        act(() => {
            registeredCallback('finish')(
                visitEvent({ showProgress: true, completed: true }),
            );
        });
        await waitFor(() =>
            expect(screen.getByTestId('route-progress-bar')).toHaveAttribute(
                'data-phase',
                'idle',
            ),
        );
    });

    it('unsubscribes both listeners on unmount', () => {
        const { unmount } = render(<RouteProgressBar />);
        const offStart = vi.mocked(router.on).mock.results[0].value;
        const offFinish = vi.mocked(router.on).mock.results[1].value;
        unmount();
        expect(offStart).toHaveBeenCalled();
        expect(offFinish).toHaveBeenCalled();
    });
});
