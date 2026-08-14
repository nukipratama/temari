import { router } from '@inertiajs/react';
import { act, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import RunHydratingNotice from './RunHydratingNotice';

vi.mock('@inertiajs/react', () => ({
    router: { reload: vi.fn() },
}));

const reload = vi.mocked(router.reload);

beforeEach(() => {
    vi.useFakeTimers();
    reload.mockClear();
});

afterEach(() => {
    vi.useRealTimers();
});

function setVisibility(state: DocumentVisibilityState) {
    Object.defineProperty(document, 'visibilityState', {
        configurable: true,
        get: () => state,
    });
}

describe('RunHydratingNotice', () => {
    it('renders nothing once the run is fully hydrated', () => {
        const { container } = render(<RunHydratingNotice hydrating={false} />);

        expect(container).toBeEmptyDOMElement();
    });

    it('explains what is present and what is still coming', () => {
        render(<RunHydratingNotice hydrating />);

        expect(screen.getByRole('status')).toHaveTextContent(
            /still filling this run in/i,
        );
        expect(screen.getByRole('status')).toHaveTextContent(
            /distance, time and pace/i,
        );
        expect(screen.getByRole('status')).toHaveTextContent(
            /splits, heart-rate zones, effort score and its card/i,
        );
    });

    it('reloads the page on an interval so the rest can land without a manual refresh', () => {
        setVisibility('visible');
        render(<RunHydratingNotice hydrating />);

        expect(reload).not.toHaveBeenCalled();

        vi.advanceTimersByTime(8000);
        expect(reload).toHaveBeenCalledTimes(1);

        vi.advanceTimersByTime(8000);
        expect(reload).toHaveBeenCalledTimes(2);
    });

    it('does not poll a backgrounded tab', () => {
        setVisibility('hidden');
        render(<RunHydratingNotice hydrating />);

        vi.advanceTimersByTime(8000 * 3);

        expect(reload).not.toHaveBeenCalled();
    });

    it('gives up after the poll cap rather than reloading forever', () => {
        setVisibility('visible');
        render(<RunHydratingNotice hydrating />);

        act(() => vi.advanceTimersByTime(8000 * 40));

        expect(reload).toHaveBeenCalledTimes(30);
    });

    it('warns that the deeper fetch queues behind runs finishing right now', () => {
        render(<RunHydratingNotice hydrating />);

        expect(screen.getByRole('status')).toHaveTextContent(
            /queues behind runs finishing right now/i,
        );
    });

    it('stops promising a self-refresh once it has stopped polling', () => {
        setVisibility('visible');
        render(<RunHydratingNotice hydrating />);

        expect(screen.getByRole('status')).toHaveTextContent(
            /this page refreshes itself/i,
        );

        act(() => vi.advanceTimersByTime(8000 * 40));

        const notice = screen.getByRole('status');
        expect(notice).not.toHaveTextContent(/this page refreshes itself/i);
        expect(notice).toHaveTextContent(/i stopped reloading on your behalf/i);
    });

    it('offers a working manual check only after it has stopped polling', () => {
        setVisibility('visible');
        render(<RunHydratingNotice hydrating />);

        expect(
            screen.queryByRole('button', { name: /check again/i }),
        ).toBeNull();

        act(() => vi.advanceTimersByTime(8000 * 40));
        reload.mockClear();

        fireEvent.click(screen.getByRole('button', { name: /check again/i }));

        expect(reload).toHaveBeenCalledTimes(1);
    });

    it('never polls when there is nothing to wait for', () => {
        setVisibility('visible');
        render(<RunHydratingNotice hydrating={false} />);

        vi.advanceTimersByTime(8000 * 5);

        expect(reload).not.toHaveBeenCalled();
    });
});
