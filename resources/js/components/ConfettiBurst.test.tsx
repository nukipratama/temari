import { act, render } from '@testing-library/react';
import { useReducedMotion as useFmReducedMotion } from 'framer-motion';
import { describe, expect, it, vi } from 'vitest';

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
        const { container, rerender } = render(
            <ConfettiBurst burstKey={null} count={3} durationMs={1000} />,
        );
        expect(container.firstChild).toBeNull();
        rerender(<ConfettiBurst burstKey="a" count={3} durationMs={1000} />);
        expect(container.querySelectorAll('span').length).toBe(3);
    });

    it('renders nothing when reduced-motion is set even with a fresh burstKey', () => {
        vi.mocked(useFmReducedMotion).mockReturnValue(true);
        const { container } = render(
            <ConfettiBurst burstKey="x" count={5} durationMs={1000} />,
        );
        expect(container.firstChild).toBeNull();
    });

    it('clears the timer on unmount before durationMs fires', () => {
        vi.mocked(useFmReducedMotion).mockReturnValue(false);
        const clearSpy = vi.spyOn(globalThis, 'clearTimeout');
        const { unmount } = render(
            <ConfettiBurst burstKey="y" count={1} durationMs={5000} />,
        );
        unmount();
        expect(clearSpy).toHaveBeenCalled();
        clearSpy.mockRestore();
    });

    it('respects custom count', () => {
        vi.mocked(useFmReducedMotion).mockReturnValue(false);
        const { container } = render(
            <ConfettiBurst burstKey={1} count={7} durationMs={500} />,
        );
        expect(container.querySelectorAll('span').length).toBe(7);
    });

    it('auto-unmounts particles after durationMs', () => {
        vi.mocked(useFmReducedMotion).mockReturnValue(false);
        vi.useFakeTimers();
        try {
            const { container } = render(
                <ConfettiBurst burstKey="z" count={1} durationMs={1000} />,
            );
            expect(container.querySelectorAll('span').length).toBe(1);
            act(() => {
                vi.advanceTimersByTime(1000);
            });
            expect(container.firstChild).toBeNull();
        } finally {
            vi.useRealTimers();
        }
    });
});
