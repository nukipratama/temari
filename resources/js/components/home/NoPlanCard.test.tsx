import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import NoPlanCard from './NoPlanCard';

describe('NoPlanCard', () => {
    it("renders the prototype's empty plan state with a way into Plan", () => {
        render(<NoPlanCard />);

        expect(screen.getByText('No plan yet.')).toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: /Set up a plan/ }),
        ).toHaveAttribute('href', '/plan');
    });

    it('draws the FaceIcon the prototype puts beside the copy', () => {
        const { container } = render(<NoPlanCard />);

        expect(container.querySelector('[data-face-icon]')).toBeInTheDocument();
    });

    it("keeps the week's own numbers, which the plan card would otherwise carry", () => {
        render(
            <NoPlanCard
                snapshot={{
                    id: 1,
                    user_id: 1,
                    week_ending: '2026-01-11',
                    runs: 3,
                    distance_km: 18.2,
                    weekly_trimp: 214,
                    ctl_42d: 42,
                    atl_7d: 44.5,
                    form: -2.5,
                    form_status: 'optimal',
                    avg_decoupling: 3.2,
                    monotony: 1.4,
                    strain: 392,
                }}
            />,
        );

        expect(
            screen.getByText('this week · 18.2 km · 214 trimp'),
        ).toBeInTheDocument();
    });

    it('dashes the week out when no snapshot has been written yet', () => {
        render(<NoPlanCard />);

        expect(
            screen.getByText('this week · — km · — trimp'),
        ).toBeInTheDocument();
    });
});
