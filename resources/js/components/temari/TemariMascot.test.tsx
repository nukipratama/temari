import { act, render } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import * as inertia from '@inertiajs/react';
import TemariMascot from './TemariMascot';

describe('TemariMascot', () => {
    it('renders the TemariCharacter SVG inside the motion wrapper', () => {
        const { container } = render(<TemariMascot mood="glow" />);
        expect(container.querySelectorAll('svg').length).toBe(1);
    });

    it('forwards aria-label to the wrapper', () => {
        const { container } = render(<TemariMascot mood="bouncy" aria-label="mood bouncy" />);
        expect(container.firstElementChild?.getAttribute('aria-label')).toBe('mood bouncy');
    });

    it('renders without crash under mood-aware idle', () => {
        const { container } = render(<TemariMascot mood="wobble" idle="mood" />);
        expect(container.firstElementChild).toBeTruthy();
    });

    it('renders with breath idle for the squished mood', () => {
        const { container } = render(<TemariMascot mood="squished" idle="breath" />);
        expect(container.firstElementChild).toBeTruthy();
    });

    it('renders without idle when idle is "none"', () => {
        const { container } = render(<TemariMascot mood="glow" idle="none" />);
        expect(container.firstElementChild).toBeTruthy();
    });

    it('respects sizeClass override', () => {
        const { container } = render(<TemariMascot mood="dim" sizeClass="h-9 w-9" />);
        expect(container.firstElementChild?.className).toContain('h-9');
    });

    it('falls through to breath when given an unknown mood with idle="mood"', () => {
        const { container } = render(
            <TemariMascot mood={'mystery' as unknown as 'glow'} idle="mood" />,
        );
        expect(container.firstElementChild).toBeTruthy();
    });

    it('schedules + fires the idle fidget tick when timers advance', () => {
        vi.useFakeTimers();
        try {
            const { container, unmount } = render(<TemariMascot mood="glow" idle="breath" />);
            act(() => {
                vi.advanceTimersByTime(25_000);
            });
            expect(container.firstElementChild).toBeTruthy();
            unmount();
        } finally {
            vi.useRealTimers();
        }
    });

    it('accepts ornaments prop without crashing', () => {
        const { container } = render(<TemariMascot mood="glow" ornaments />);
        expect(container.firstElementChild).toBeTruthy();
    });

    it('falls back to empty unlocks when usePage throws (non-Inertia context)', () => {
        const spy = vi.spyOn(inertia, 'usePage').mockImplementation(() => {
            throw new Error('outside Inertia');
        });
        try {
            const { container } = render(<TemariMascot mood="glow" />);
            expect(container.querySelector('svg')).toBeInTheDocument();
        } finally {
            spy.mockRestore();
        }
    });

    it('honours an explicit unlockedAccessories prop override', () => {
        const { container } = render(
            <TemariMascot
                mood="glow"
                unlockedAccessories={['accessory.headband_legendaris']}
            />,
        );
        expect(container.innerHTML).toContain('y="20.5"');
    });

    it('skips unlock overlays when showUnlocks is false', () => {
        const { container } = render(
            <TemariMascot
                mood="glow"
                showUnlocks={false}
                unlockedAccessories={['accessory.headband_legendaris']}
            />,
        );
        expect(container.innerHTML).not.toContain('y="20.5"');
    });
});
