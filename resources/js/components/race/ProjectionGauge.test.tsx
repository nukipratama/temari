import { render, screen, waitFor } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import ProjectionGauge from './ProjectionGauge';

describe('ProjectionGauge', () => {
    it('labels the low and high ends of the projected range', () => {
        render(
            <ProjectionGauge
                lowSec={2_900}
                predictedSec={3_100}
                highSec={3_300}
            />,
        );

        expect(screen.getByText('48:20')).toBeInTheDocument();
        expect(screen.getByText('55:00')).toBeInTheDocument();
    });

    it('draws the fill arc up to the predicted ratio between low and high', async () => {
        const { container } = render(
            <ProjectionGauge
                lowSec={2_900}
                predictedSec={3_100}
                highSec={3_300}
            />,
        );

        // predicted sits exactly halfway between low and high here.
        await waitFor(() => {
            const fill = container.querySelectorAll('path')[1];
            const dasharray = fill.getAttribute('stroke-dasharray') ?? '';
            expect(Number(dasharray.split(' ')[0])).toBeCloseTo(50, 0);
        });
    });

    it('clamps the fill when the prediction sits outside the low-high span', async () => {
        const { container } = render(
            <ProjectionGauge
                lowSec={2_900}
                predictedSec={9_999}
                highSec={3_300}
            />,
        );

        await waitFor(() => {
            const fill = container.querySelectorAll('path')[1];
            const dasharray = fill.getAttribute('stroke-dasharray') ?? '';
            expect(Number(dasharray.split(' ')[0])).toBeCloseTo(100, 0);
        });
    });
});
