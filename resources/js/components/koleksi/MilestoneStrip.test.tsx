import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import MilestoneStrip from './MilestoneStrip';

describe('MilestoneStrip', () => {
    it('renders the target and delta labels for a sub-hour target', () => {
        // 50:00 target, 1:30 to go — mirrors Rekor.tsx passing a 10K chase.
        const { container } = render(
            <MilestoneStrip
                targetSec={3000}
                deltaSec={90}
                distanceLabel="10K"
            />,
        );

        expect(screen.getByText(/Next target/)).toBeInTheDocument();
        // Distance label is interpolated into the target line, so match the row.
        expect(screen.getByText(/10K/)).toBeInTheDocument();
        // Sub-hour target renders as M:SS via formatDurationHMS (inside an <em>).
        expect(screen.getByText('50:00')).toBeInTheDocument();
        // Delta renders as M:SS too.
        expect(screen.getByText('1:30')).toBeInTheDocument();
        expect(screen.getByText(/to go/)).toBeInTheDocument();
        // No extra className applied by default.
        expect(container.firstChild).not.toHaveClass('relative');
    });

    it('renders an hour-form target (H:MM:SS) and absolutes a negative delta', () => {
        // 1:45:00 Half Marathon target; negative delta exercises Math.abs.
        render(
            <MilestoneStrip
                targetSec={6300}
                deltaSec={-125}
                distanceLabel="Half Marathon"
                className="relative mt-6"
            />,
        );

        expect(screen.getByText('1:45:00')).toBeInTheDocument();
        expect(screen.getByText(/Half Marathon/)).toBeInTheDocument();
        // Math.abs(-125) => 2:05.
        expect(screen.getByText('2:05')).toBeInTheDocument();
    });

    it('applies the optional className to the root element', () => {
        const { container } = render(
            <MilestoneStrip
                targetSec={1800}
                deltaSec={30}
                distanceLabel="5K"
                className="relative mt-6"
            />,
        );

        expect(container.firstChild).toHaveClass('relative', 'mt-6');
    });
});
