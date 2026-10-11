import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import Race from '@/pages/Race';
import { setMockDeferred } from '@/test/setup';

import RaceSkeleton, { RaceBodySkeleton } from './RaceSkeleton';

describe('RaceSkeleton', () => {
    it('keeps the real heading and the plan link', () => {
        render(<RaceSkeleton />);

        expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent(
            'your race.',
        );
        expect(screen.getByRole('link', { name: 'plan' })).toHaveAttribute(
            'href',
            '/plan',
        );
    });

    it('lays out what the race shows while its props are deferred', () => {
        setMockDeferred(['race', 'projection', 'past_races']);
        const page = render(<Race has_race />).container.querySelector(
            '.reveal',
        )!;
        const skeleton = render(<RaceSkeleton />).container.firstElementChild!;

        expect(page.innerHTML).toContain(
            render(<RaceBodySkeleton />).container.innerHTML,
        );
        expect(skeleton.innerHTML).toContain(
            render(<RaceBodySkeleton />).container.innerHTML,
        );
    });

    it('appears at once rather than through the page entrance', () => {
        const { container } = render(<RaceSkeleton />);

        expect(container.firstElementChild).not.toHaveClass('reveal');
    });
});
