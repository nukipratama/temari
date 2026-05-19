import { act, render } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { useReducedMotion as useFmReducedMotion } from 'framer-motion';
import ConfettiBurst from './ConfettiBurst';

vi.mock('framer-motion', async (importOriginal) => {
    const actual = await importOriginal<typeof import('framer-motion')>();
    return {
        ...actual,
        useReducedMotion: vi.fn().mockReturnValue(false),
    };
});

describe('ConfettiBurst', () => {
    it('renders nothing when burstKey is null', () => {
        vi.mocked(useFmReducedMotion).mockReturnValue(false);
        const { container } = render(<ConfettiBurst burstKey={null} />);
        expect(container.firstChild).toBeNull();
    });

    it('mounts particles when burstKey transitions from null', () => {
        vi.mocked(useFmReducedMotion).mockReturnValue(false);
        const { container, rerender } = render(<ConfettiBurst burstKey={null} count={3} durationMs={1000} />);
        expect(container.firstChild).toBeNull();
        rerender(<ConfettiBurst burstKey="a" count={3} durationMs={1000} />);
        expect(container.querySelectorAll('span').length).toBe(3);
    });

    it('renders nothing when reduced-motion is set even with a fresh burstKey', () => {
        vi.mocked(useFmReducedMotion).mockReturnValue(true);
        const { container } = render(<ConfettiBurst burstKey="x" count={5} durationMs={1000} />);
        expect(container.firstChild).toBeNull();
    });

    it('clears the timer on unmount before durationMs fires', () => {
        vi.mocked(useFmReducedMotion).mockReturnValue(false);
        const clearSpy = vi.spyOn(globalThis, 'clearTimeout');
        const { unmount } = render(<ConfettiBurst burstKey="y" count={1} durationMs={5000} />);
        unmount();
        expect(clearSpy).toHaveBeenCalled();
        clearSpy.mockRestore();
    });

    it('respects custom count', () => {
        vi.mocked(useFmReducedMotion).mockReturnValue(false);
        const { container } = render(<ConfettiBurst burstKey={1} count={7} durationMs={500} />);
        expect(container.querySelectorAll('span').length).toBe(7);
    });

    it('invokes the scheduled unmount callback', async () => {
        vi.mocked(useFmReducedMotion).mockReturnValue(false);
        const captured: Array<() => void> = [];
        const realSetTimeout = globalThis.setTimeout;
        const spy = vi.spyOn(globalThis, 'setTimeout').mockImplementation(((
            handler: TimerHandler,
            timeout?: number,
        ) => {
            if (typeof handler === 'function') captured.push(handler as () => void);
            return realSetTimeout(() => {}, timeout);
        }) as unknown as typeof globalThis.setTimeout);

        try {
            const { container } = render(<ConfettiBurst burstKey="z" count={1} durationMs={9999} />);
            expect(container.querySelectorAll('span').length).toBe(1);
            const cb = captured.at(-1);
            expect(cb).toBeTypeOf('function');
            await act(async () => {
                cb?.();
            });
            expect(container.firstChild).toBeNull();
        } finally {
            spy.mockRestore();
        }
    });
});
