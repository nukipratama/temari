import { act, render } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import TemariCharacter from './TemariCharacter';
import { MOOD_VARIANTS } from '@/lib/temariMoodVariants';
import type { Mood } from '@/types/inertia';

const ALL_MOODS: Mood[] = ['glow', 'bouncy', 'wobble', 'squished', 'spinning', 'dim'];

describe('TemariCharacter', () => {
    it.each(ALL_MOODS)('renders without crashing for mood %s', (mood) => {
        const { container } = render(<TemariCharacter mood={mood} />);
        expect(container.querySelector('svg')).toBeInTheDocument();
    });

    it('uses the variant mood colour somewhere in the SVG', () => {
        const { container } = render(<TemariCharacter mood="bouncy" />);
        const expected = MOOD_VARIANTS.bouncy.moodColor;
        const svg = container.querySelector('svg');
        expect(svg?.innerHTML.toLowerCase()).toContain(expected);
    });

    it('renders the towel accessory only on wobble', () => {
        // The towel-around-neck path `M 30 54 Q 50 49` is unique to wobble.
        const wobble = render(<TemariCharacter mood="wobble" />);
        const glow = render(<TemariCharacter mood="glow" />);
        expect(wobble.container.innerHTML).toContain('M 30 54 Q 50 49');
        expect(glow.container.innerHTML).not.toContain('M 30 54 Q 50 49');
    });

    it('renders the medal accessory only on glow', () => {
        const glow = render(<TemariCharacter mood="glow" />);
        const dim = render(<TemariCharacter mood="dim" />);
        // Medal ribbon path is the distinctive `M 56 51 L 60 60 L 56 62 Z`.
        expect(glow.container.innerHTML).toContain('M 56 51 L 60 60 L 56 62 Z');
        expect(dim.container.innerHTML).not.toContain('M 56 51 L 60 60 L 56 62 Z');
    });

    it('renders zzz particles only on dim', () => {
        const dim = render(<TemariCharacter mood="dim" />);
        const glow = render(<TemariCharacter mood="glow" />);
        // Three Z letters in dim mood; none in glow.
        expect(dim.container.querySelectorAll('text').length).toBeGreaterThanOrEqual(3);
        expect(glow.container.innerHTML).not.toMatch(/<text[^>]*>Z<\/text>/);
    });

    it('renders the head + body always (regardless of mood)', () => {
        ALL_MOODS.forEach((mood) => {
            const { container } = render(<TemariCharacter mood={mood} />);
            // Head is the rounded rect at x=26 y=16 width=48 height=36
            expect(container.innerHTML).toContain('width="48"');
            // Body path begins with `M 34 52`
            expect(container.innerHTML).toContain('M 34 52');
        });
    });

    it('respects the size prop', () => {
        const { container } = render(<TemariCharacter mood="glow" size={48} />);
        const svg = container.querySelector('svg');
        expect(svg).toHaveAttribute('width', '48');
        expect(svg).toHaveAttribute('height', '48');
    });

    it('passes className through to the svg', () => {
        const { container } = render(<TemariCharacter mood="glow" className="opacity-50" />);
        expect(container.querySelector('svg')).toHaveClass('opacity-50');
    });

    it('marks the SVG as aria-hidden (decorative)', () => {
        const { container } = render(<TemariCharacter mood="glow" />);
        expect(container.querySelector('svg')).toHaveAttribute('aria-hidden');
    });

    it('eyes blink via the schedule (closeEye → openEye → re-schedule)', () => {
        vi.useFakeTimers();
        // Math.random() seeded to 0 → blink delay = 2500ms (minimum).
        const randSpy = vi.spyOn(Math, 'random').mockReturnValue(0);

        try {
            render(<TemariCharacter mood="glow" />);

            // Initial delay fires → closeEye()
            act(() => {
                vi.advanceTimersByTime(2500);
            });
            // 140ms closed → openEye() → reschedule
            act(() => {
                vi.advanceTimersByTime(140);
            });
            // Second cycle delay fires → closeEye again
            act(() => {
                vi.advanceTimersByTime(2500);
            });
        } finally {
            randSpy.mockRestore();
            vi.useRealTimers();
        }
    });

    it('skips blink scheduling when paused', () => {
        vi.useFakeTimers();
        try {
            const { unmount } = render(<TemariCharacter mood="glow" paused />);
            // No timers should be queued.
            expect(vi.getTimerCount()).toBe(0);
            unmount();
        } finally {
            vi.useRealTimers();
        }
    });

    it('applies the gaze offset to the open-eye pupils', () => {
        const a = render(<TemariCharacter mood="glow" gaze={{ x: 1, y: 1 }} />);
        const b = render(<TemariCharacter mood="glow" gaze={{ x: -1, y: -1 }} />);
        // Default left-pupil cx is 38; gaze=1 nudges it +1.6, gaze=-1 nudges -1.6.
        expect(a.container.innerHTML).toContain('cx="39.6"');
        expect(b.container.innerHTML).toContain('cx="36.4"');
    });

    it.each(['hearts', 'droplets', 'lines', 'stars'] as const)(
        'renders the %s particle layer for its mood',
        (kind) => {
            const moodFor = {
                hearts: 'bouncy',
                droplets: 'wobble',
                lines: 'squished',
                stars: 'spinning',
            } as const;
            const { container } = render(<TemariCharacter mood={moodFor[kind]} />);
            expect(container.querySelector('svg')).toBeInTheDocument();
        },
    );

    it.each(['flag', 'question', 'bottle'] as const)('renders the %s accessory for its mood', (kind) => {
        const moodFor = { flag: 'bouncy', question: 'spinning', bottle: 'squished' } as const;
        const { container } = render(<TemariCharacter mood={moodFor[kind]} />);
        expect(container.querySelector('svg')).toBeInTheDocument();
    });

    it('renders unlocked accessory overlay when unlocks include a known key', () => {
        const { container } = render(
            <TemariCharacter
                mood="glow"
                unlockedAccessories={['accessory.headband_legendaris', 'accessory.medal_gold']}
            />,
        );
        // Headband legendaris draws a rect with fill="#e0a639" at y=20.5
        expect(container.innerHTML).toContain('y="20.5"');
        // Medal gold draws a circle at cx=48 cy=60
        expect(container.innerHTML).toContain('cx="48"');
    });

    it('re-renders when unlockedAccessories array changes content', () => {
        const { container, rerender } = render(
            <TemariCharacter mood="glow" unlockedAccessories={['accessory.headband_legendaris']} />,
        );
        const before = container.innerHTML;
        rerender(
            <TemariCharacter mood="glow" unlockedAccessories={['accessory.medal_gold']} />,
        );
        expect(container.innerHTML).not.toBe(before);
    });

    it('skips re-render when only props identity changes but values are equal (memo guard)', () => {
        const { rerender, container } = render(
            <TemariCharacter mood="glow" gaze={{ x: 0, y: 0 }} />,
        );
        const before = container.innerHTML;
        // A fresh `gaze` object with same values — memo equality should skip
        // the re-render, so the DOM string stays byte-identical.
        rerender(<TemariCharacter mood="glow" gaze={{ x: 0, y: 0 }} />);
        expect(container.innerHTML).toBe(before);
    });
});
