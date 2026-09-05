import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import PlanRaceTabs from './PlanRaceTabs';

describe('PlanRaceTabs', () => {
    it('renders both tabs linking to their pages', () => {
        render(<PlanRaceTabs active="plan" />);
        expect(screen.getByText('schedule').closest('a')).toHaveAttribute(
            'href',
            '/plan',
        );
        expect(screen.getByText('race goal').closest('a')).toHaveAttribute(
            'href',
            '/race',
        );
    });

    it('marks the active tab with aria-current', () => {
        render(<PlanRaceTabs active="race" />);
        expect(screen.getByText('race goal').closest('a')).toHaveAttribute(
            'aria-current',
            'page',
        );
        expect(screen.getByText('schedule').closest('a')).not.toHaveAttribute(
            'aria-current',
        );
    });

    it('raises only the active tab off the shared track', () => {
        render(<PlanRaceTabs active="race" />);
        expect(screen.getByText('race goal').closest('a')).toHaveClass(
            'bg-card',
        );
        expect(screen.getByText('schedule').closest('a')).not.toHaveClass(
            'bg-card',
        );
    });
});
