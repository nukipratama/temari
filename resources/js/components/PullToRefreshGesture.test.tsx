import { router } from '@inertiajs/react';
import { fireEvent, render, screen } from '@testing-library/react';
import { useRef } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import PullToRefreshGesture, { type PullMotion } from './PullToRefreshGesture';

function Host({
    onMotion,
}: Readonly<{ onMotion: (motion: PullMotion) => void }>) {
    const containerRef = useRef<HTMLDivElement>(null);

    return (
        <div ref={containerRef} data-testid="container">
            <PullToRefreshGesture
                containerRef={containerRef}
                onMotion={onMotion}
            />
            <p>page body</p>
        </div>
    );
}

function touch(clientY: number) {
    return { touches: [{ identifier: 1, clientX: 10, clientY }] };
}

beforeEach(() => {
    vi.mocked(router.reload).mockClear();
    vi.stubGlobal(
        'matchMedia',
        vi.fn(() => ({
            matches: false,
            addEventListener: vi.fn(),
            removeEventListener: vi.fn(),
        })),
    );
});

describe('PullToRefreshGesture', () => {
    it('renders nothing and reports a resting motion before any pull', () => {
        const onMotion = vi.fn();
        render(<Host onMotion={onMotion} />);

        expect(screen.queryByRole('status')).not.toBeInTheDocument();
        expect(onMotion).toHaveBeenLastCalledWith({ slide: 0, animate: true });
    });

    it('reports the slide while pulling and reloads past the threshold', () => {
        const onMotion = vi.fn();
        render(<Host onMotion={onMotion} />);
        const body = screen.getByText('page body');

        fireEvent.touchStart(body, touch(100));
        fireEvent.touchMove(body, touch(160));

        expect(onMotion).toHaveBeenLastCalledWith({
            slide: 42,
            animate: false,
        });
        expect(screen.getByRole('status')).toHaveTextContent('pull to refresh');

        fireEvent.touchMove(body, touch(220));
        fireEvent.touchEnd(body, { changedTouches: [] });

        expect(router.reload).toHaveBeenCalledTimes(1);
        expect(onMotion).toHaveBeenLastCalledWith({ slide: 56, animate: true });
        expect(screen.getByRole('status')).toHaveTextContent('refreshing');
    });
});
