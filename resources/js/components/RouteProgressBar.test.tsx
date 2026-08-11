import type { GlobalEvent } from '@inertiajs/core';

import { router } from '@inertiajs/react';
import { act, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import RouteProgressBar from './RouteProgressBar';

function visitEvent(showProgress: boolean): GlobalEvent<'start' | 'finish'> {
    return {
        detail: { visit: { showProgress } },
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
            registeredCallback('start')(visitEvent(true));
        });
        expect(screen.getByTestId('route-progress-bar')).toHaveAttribute(
            'data-phase',
            'loading',
        );
    });

    it('ignores a background/partial reload (showProgress: false)', () => {
        render(<RouteProgressBar />);
        act(() => {
            registeredCallback('start')(visitEvent(false));
        });
        expect(screen.getByTestId('route-progress-bar')).toHaveAttribute(
            'data-phase',
            'idle',
        );
    });

    it('moves to done when a full navigation finishes', () => {
        render(<RouteProgressBar />);
        act(() => {
            registeredCallback('start')(visitEvent(true));
        });
        act(() => {
            registeredCallback('finish')(visitEvent(true));
        });
        expect(screen.getByTestId('route-progress-bar')).toHaveAttribute(
            'data-phase',
            'done',
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
