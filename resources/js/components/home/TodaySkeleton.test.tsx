import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import TodaySkeleton from './TodaySkeleton';

describe('TodaySkeleton', () => {
    it('keeps the page title for screen readers', () => {
        render(<TodaySkeleton />);

        expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent(
            'today',
        );
    });

    it("stacks today's session, the week's plan and the past-you read in lanes", () => {
        const { container } = render(<TodaySkeleton />);

        expect(container.querySelectorAll('[data-slot="lane"]')).toHaveLength(
            3,
        );
    });

    it("draws the week's seven days", () => {
        const { container } = render(<TodaySkeleton />);

        expect(
            container.querySelectorAll('.grid-cols-7 > .skeleton'),
        ).toHaveLength(7);
    });

    it('appears at once rather than through the page entrance', () => {
        const { container } = render(<TodaySkeleton />);

        expect(container.firstElementChild).not.toHaveClass('reveal');
    });
});
