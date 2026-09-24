import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import ProjectionRangeBar from './ProjectionRangeBar';

function marker(container: HTMLElement, name: string): HTMLElement {
    return container.querySelector(`[data-marker="${name}"]`) as HTMLElement;
}

function leftOf(element: HTMLElement): number {
    return Number.parseFloat(element.style.left);
}

describe('ProjectionRangeBar', () => {
    it('spans the goal and the range with padding, and places each marker on it', () => {
        const { container } = render(
            <ProjectionRangeBar
                goalSec={3_000}
                lowSec={2_900}
                predictedSec={3_100}
                highSec={3_300}
            />,
        );

        expect(leftOf(marker(container, 'range'))).toBeCloseTo(8.333, 2);
        expect(
            Number.parseFloat(marker(container, 'range').style.width),
        ).toBeCloseTo(83.333, 2);
        expect(leftOf(marker(container, 'estimate'))).toBeCloseTo(50, 2);
        expect(leftOf(marker(container, 'goal'))).toBeCloseTo(29.167, 2);
    });

    it('widens the axis to a goal outside the range', () => {
        const { container } = render(
            <ProjectionRangeBar
                goalSec={2_400}
                lowSec={2_900}
                predictedSec={3_100}
                highSec={3_300}
            />,
        );

        expect(leftOf(marker(container, 'goal'))).toBeCloseTo(8.333, 2);
        expect(
            leftOf(marker(container, 'range')) +
                Number.parseFloat(marker(container, 'range').style.width),
        ).toBeCloseTo(91.667, 2);
    });

    it('labels the goal and the range ends', () => {
        render(
            <ProjectionRangeBar
                goalSec={3_000}
                lowSec={2_900}
                predictedSec={3_100}
                highSec={3_300}
            />,
        );

        expect(screen.getByText('goal 50:00')).toBeInTheDocument();
        expect(screen.getByText('48:20')).toBeInTheDocument();
        expect(screen.getByText('55:00')).toBeInTheDocument();
        expect(
            screen.getByRole('img', {
                name: 'goal 50:00, projected 48:20 to 55:00, best estimate 51:40',
            }),
        ).toBeInTheDocument();
    });
});
