import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import PlanRaceTabs from './PlanRaceTabs';

describe('PlanRaceTabs', () => {
    it('renders both tabs linking to their pages', () => {
        render(<PlanRaceTabs active="plan" />);
        expect(screen.getByText('Schedule').closest('a')).toHaveAttribute(
            'href',
            '/plan',
        );
        expect(screen.getByText('Race Goal').closest('a')).toHaveAttribute(
            'href',
            '/race',
        );
    });

    it('marks the active tab with aria-current', () => {
        render(<PlanRaceTabs active="race" />);
        expect(screen.getByText('Race Goal').closest('a')).toHaveAttribute(
            'aria-current',
            'page',
        );
        expect(screen.getByText('Schedule').closest('a')).not.toHaveAttribute(
            'aria-current',
        );
    });
});
