import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import PrCard from './PrCard';

describe('PrCard', () => {
    it('renders the category, time, and setAt date', () => {
        render(<PrCard category="5K" time="22:14" setAt="14 Mei 2026" activityId={null} />);
        expect(screen.getByText('5K')).toBeInTheDocument();
        expect(screen.getByText('22:14')).toBeInTheDocument();
        expect(screen.getByText('14 Mei 2026')).toBeInTheDocument();
    });

    it('links to the activity when an activityId is given', () => {
        render(<PrCard category="10K" time="48:30" setAt="1 Mei 2026" activityId={42} />);
        expect(screen.getByText('10K').closest('a')).toHaveAttribute('href', '/aktivitas/42');
    });

    it('renders as a non-link card when there is no activityId', () => {
        const { container } = render(<PrCard category="10K" time="48:30" setAt="1 Mei 2026" activityId={null} />);
        expect(container.querySelector('a')).toBeNull();
    });

    it('shows the run name only when provided', () => {
        const { rerender } = render(
            <PrCard category="5K" time="22:14" setAt="14 Mei 2026" activityId={null} runName="Lari Pagi" />,
        );
        expect(screen.getByText('Lari Pagi')).toBeInTheDocument();

        rerender(<PrCard category="5K" time="22:14" setAt="14 Mei 2026" activityId={null} />);
        expect(screen.queryByText('Lari Pagi')).toBeNull();
    });
});
