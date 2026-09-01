import { render, screen, waitFor } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import ProjectionBlock from './ProjectionBlock';

const PROJECTION = {
    predicted_sec: 3_100,
    low_sec: 2_900,
    high_sec: 3_300,
    sample_size: 2,
    confidence: 'medium' as const,
};

describe('ProjectionBlock', () => {
    it('draws the gauge bounds, the predicted finish and what it rests on', async () => {
        render(<ProjectionBlock projection={PROJECTION} />);

        expect(screen.getByText('Projected finish')).toBeInTheDocument();
        expect(screen.getByText(/2 PRs/)).toBeInTheDocument();
        expect(screen.getByText(/moderate range/)).toBeInTheDocument();
        // Both the gauge bounds and the predicted time tally up from 0
        // (tier-2 count-up), so wait for them to settle.
        await waitFor(() => {
            expect(screen.getByText('48:20')).toBeInTheDocument();
            expect(screen.getByText('55:00')).toBeInTheDocument();
            expect(screen.getByText('51:40')).toBeInTheDocument();
        });
    });

    it('says "1 PR" rather than "1 PRs" on a single-record sample', () => {
        render(
            <ProjectionBlock
                projection={{
                    ...PROJECTION,
                    sample_size: 1,
                    confidence: 'low',
                }}
            />,
        );

        expect(screen.getByText(/1 PR \(/)).toBeInTheDocument();
        expect(screen.getByText(/thin PR sample/)).toBeInTheDocument();
    });

    it('explains the gap instead of drawing an empty gauge with no PR to anchor on', () => {
        render(<ProjectionBlock projection={null} />);

        expect(screen.getByText(/No personal record yet/)).toBeInTheDocument();
        expect(screen.queryByText('Projected finish')).not.toBeInTheDocument();
    });

    it('carries Temari beside the label, at the prototype placement', () => {
        const { container } = render(
            <ProjectionBlock projection={PROJECTION} />,
        );

        const face = container.querySelector('[data-face-icon]');
        expect(face).not.toBeNull();
        expect(face).toHaveAttribute('width', '18');
    });
});
