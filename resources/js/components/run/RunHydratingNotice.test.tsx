import { router } from '@inertiajs/react';
import { render, screen } from '@testing-library/react';
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

        vi.advanceTimersByTime(8000 * 40);

        expect(reload).toHaveBeenCalledTimes(30);
    });

    it('never polls when there is nothing to wait for', () => {
        setVisibility('visible');
        render(<RunHydratingNotice hydrating={false} />);

        vi.advanceTimersByTime(8000 * 5);

        expect(reload).not.toHaveBeenCalled();
    });
});
