import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import Sparkline from './Sparkline';

describe('Sparkline', () => {
    it('draws one bar per day', () => {
        const { container } = render(
            <Sparkline values={[1, 2, 3]} ariaLabel="spend" />,
        );

        expect(container.querySelectorAll('rect')).toHaveLength(3);
    });

    it('scales the bars against the tallest day', () => {
        const { container } = render(
            <Sparkline values={[1, 2]} ariaLabel="spend" />,
        );

        const [first, second] = Array.from(container.querySelectorAll('rect'));

        expect(Number(first.getAttribute('height'))).toBeLessThan(
            Number(second.getAttribute('height')),
        );
    });

    it('draws a flat, muted stub for a day with no spend', () => {
        const { container } = render(
            <Sparkline values={[0, 4]} ariaLabel="spend" />,
        );

        const [empty] = Array.from(container.querySelectorAll('rect'));

        expect(empty.getAttribute('class')).toContain('fill-border');
    });

    it('survives an all-zero window without dividing by the peak', () => {
        const { container } = render(
            <Sparkline values={[0, 0]} ariaLabel="spend" />,
        );

        for (const rect of Array.from(container.querySelectorAll('rect'))) {
            expect(Number(rect.getAttribute('height'))).toBeGreaterThan(0);
        }
    });

    it('names itself for a screen reader', () => {
        render(<Sparkline values={[1]} ariaLabel="Nuki: daily spend" />);

        expect(screen.getByRole('img')).toHaveAccessibleName(
            'Nuki: daily spend',
        );
    });
});
