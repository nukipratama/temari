import { render, screen, within } from '@testing-library/react';
import {
    afterAll,
    afterEach,
    beforeAll,
    describe,
    expect,
    it,
    vi,
} from 'vitest';

import type { StreamSummaryLap } from '@/types/inertia';

import { SCROLL_FADE_MASK } from '@/hooks/useScrollFade';

import LapsCarousel from './LapsCarousel';

function stubRailGeometry(scrollWidth: number, clientWidth: number) {
    vi.spyOn(HTMLElement.prototype, 'scrollWidth', 'get').mockReturnValue(
        scrollWidth,
    );
    vi.spyOn(HTMLElement.prototype, 'clientWidth', 'get').mockReturnValue(
        clientWidth,
    );
}

const maskImages = new WeakMap<CSSStyleDeclaration, string>();
const styleProto: object = Object.getPrototypeOf(
    document.createElement('ul').style,
);

/** jsdom has no `maskImage` accessor, so the value React writes is recorded here. */
beforeAll(() => {
    Object.defineProperty(styleProto, 'maskImage', {
        configurable: true,
        get(this: CSSStyleDeclaration) {
            return maskImages.get(this) ?? '';
        },
        set(this: CSSStyleDeclaration, value: string | null) {
            maskImages.set(this, value ?? '');
        },
    });
});

afterAll(() => {
    Reflect.deleteProperty(styleProto, 'maskImage');
});

afterEach(() => {
    vi.restoreAllMocks();
});

const laps: StreamSummaryLap[] = [
    {
        lap: 1,
        distance_m: 3200,
        elapsed_sec: 892,
        pace: '4:39',
        avg_hr: 149,
        avg_cadence_spm: 175,
    },
    {
        lap: 2,
        distance_m: 1020,
        elapsed_sec: 261,
        pace: '4:16',
        avg_hr: 159,
        avg_cadence_spm: 179,
    },
];

describe('LapsCarousel', () => {
    it('draws one card per lap with its distance and elapsed time', () => {
        render(<LapsCarousel laps={laps} />);
        expect(screen.getByText('Laps')).toBeInTheDocument();
        expect(screen.getByText('lap 1')).toBeInTheDocument();
        expect(screen.getByText('4:39')).toBeInTheDocument();
        expect(screen.getByText('3.20 km · 14:52')).toBeInTheDocument();
        expect(screen.getByText('1.02 km · 4:21')).toBeInTheDocument();
    });

    it('picks the fastest lap out, and only that one', () => {
        render(<LapsCarousel laps={laps} />);
        const cards = screen.getAllByRole('listitem');
        expect(cards[1]).toHaveClass('ring-icon-accent');
        expect(cards[0]).not.toHaveClass('ring-icon-accent');
    });

    it('shows heart rate and cadence per lap', () => {
        render(<LapsCarousel laps={laps} />);
        const first = screen.getAllByRole('listitem')[0];
        expect(within(first).getByText('♡ 149')).toBeInTheDocument();
        expect(within(first).getByText('175')).toBeInTheDocument();
    });

    it('dashes readings the watch did not record', () => {
        render(
            <LapsCarousel
                laps={[
                    {
                        lap: 1,
                        distance_m: 1000,
                        elapsed_sec: 300,
                        pace: '5:00',
                    },
                ]}
            />,
        );
        expect(screen.getByText('♡ —')).toBeInTheDocument();
        expect(screen.getByText('—')).toBeInTheDocument();
    });

    it('highlights nothing when no lap carries a parseable pace', () => {
        render(
            <LapsCarousel
                laps={[
                    { lap: 1, distance_m: 1000, elapsed_sec: 300, pace: '' },
                ]}
            />,
        );
        expect(screen.getByRole('listitem')).not.toHaveClass(
            'ring-icon-accent',
        );
    });

    it('scrolls sideways rather than paging', () => {
        render(<LapsCarousel laps={laps} />);
        expect(screen.getByRole('list')).toHaveClass('overflow-x-auto');
        expect(screen.queryByRole('button')).not.toBeInTheDocument();
    });

    it('fades the trailing edge while laps run past it', () => {
        stubRailGeometry(612, 284);
        render(<LapsCarousel laps={laps} />);
        expect(screen.getByRole('list').style.maskImage).toBe(SCROLL_FADE_MASK);
    });

    it('leaves the rail unmasked when every lap fits', () => {
        stubRailGeometry(284, 284);
        render(<LapsCarousel laps={laps} />);
        expect(screen.getByRole('list').style.maskImage).toBe('');
    });
});
