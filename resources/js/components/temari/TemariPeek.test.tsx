import { act, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import TemariPeek from './TemariPeek';

const LINES = ['hai', 'halo', 'hey'] as const;

beforeEach(() => {
    vi.useFakeTimers();
    globalThis.sessionStorage.clear();
});

afterEach(async () => {
    // async act flushes any state update a still-pending timer fires here
    // (e.g. the hide timer left pending by a test that only advanced past the
    // show delay), so React doesn't warn about an update outside a test body.
    await act(async () => {
        vi.runOnlyPendingTimers();
    });
    vi.useRealTimers();
    globalThis.sessionStorage.clear();
});

describe('TemariPeek', () => {
    it('does not show before the delay', () => {
        render(<TemariPeek lines={LINES} delayMs={1000} visibleMs={500} />);
        expect(screen.queryByText(/hai|halo|hey/)).not.toBeInTheDocument();
    });

    it('appears after the delay and hides after visibleMs', async () => {
        render(<TemariPeek lines={LINES} delayMs={1000} visibleMs={500} />);
        act(() => {
            vi.advanceTimersByTime(1000);
        });
        expect(screen.getByText(/hai|halo|hey/)).toBeInTheDocument();
        // After visibleMs the bubble is dismissed. Animations are instant in
        // tests (MotionGlobalConfig.skipAnimations), so the exit completes and
        // the bubble leaves the DOM. The async act flushes AnimatePresence's
        // safe-to-remove tick.
        await act(async () => {
            vi.advanceTimersByTime(2000);
        });
        expect(screen.queryByText(/hai|halo|hey/)).not.toBeInTheDocument();
    });

    it('writes the session flag after first appearance', () => {
        render(<TemariPeek lines={LINES} delayMs={1000} />);
        act(() => {
            vi.advanceTimersByTime(1000);
        });
        expect(globalThis.sessionStorage.getItem('tl.temari.peek.shown')).toBe('1');
    });

    it('skips entirely when the session flag is already set', () => {
        globalThis.sessionStorage.setItem('tl.temari.peek.shown', '1');
        render(<TemariPeek lines={LINES} delayMs={1000} />);
        act(() => {
            vi.advanceTimersByTime(5000);
        });
        expect(screen.queryByText(/hai|halo|hey/)).not.toBeInTheDocument();
    });

    it('renders nothing when the lines array is empty', () => {
        const { container } = render(<TemariPeek lines={[]} />);
        expect(container.textContent).toBe('');
    });

    it('honours prefers-reduced-motion: reduce by skipping the peek', () => {
        const original = globalThis.matchMedia;
        globalThis.matchMedia = vi.fn().mockImplementation((q: string) => ({
            matches: q === '(prefers-reduced-motion: reduce)',
            media: q,
            onchange: null,
            addListener: vi.fn(),
            removeListener: vi.fn(),
            addEventListener: vi.fn(),
            removeEventListener: vi.fn(),
            dispatchEvent: vi.fn(),
        })) as unknown as typeof globalThis.matchMedia;
        try {
            render(<TemariPeek lines={LINES} delayMs={1000} />);
            act(() => {
                vi.advanceTimersByTime(5000);
            });
            expect(screen.queryByText(/hai|halo|hey/)).not.toBeInTheDocument();
        } finally {
            globalThis.matchMedia = original;
        }
    });
});
