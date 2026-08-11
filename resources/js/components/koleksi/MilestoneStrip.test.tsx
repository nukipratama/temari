import { render, screen, waitFor } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import MilestoneStrip from './MilestoneStrip';

describe('MilestoneStrip', () => {
    it('renders the target and delta labels for a sub-hour target', async () => {
        // 50:00 target, 1:30 to go — mirrors Rekor.tsx passing a 10K chase.
        const { container } = render(
            <MilestoneStrip
                targetSec={3000}
                deltaSec={90}
                currentSec={3090}
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
        // Progress toward the target (targetSec / currentSec) count-up settles
        // on 97% (3000/3090).
        await waitFor(() =>
            expect(screen.getByRole('progressbar')).toHaveAttribute(
                'aria-valuenow',
                '97',
            ),
        );
        // No extra className applied by default.
        expect(container.firstChild).not.toHaveClass('relative');
    });

    it('renders an hour-form target (H:MM:SS) and absolutes a negative delta', async () => {
        // 1:45:00 Half Marathon target; negative delta exercises Math.abs.
        render(
            <MilestoneStrip
                targetSec={6300}
                deltaSec={-125}
                currentSec={6175}
                distanceLabel="Half Marathon"
                className="relative mt-6"
            />,
        );

        expect(screen.getByText('1:45:00')).toBeInTheDocument();
        expect(screen.getByText(/Half Marathon/)).toBeInTheDocument();
        // Math.abs(-125) => 2:05.
        expect(screen.getByText('2:05')).toBeInTheDocument();
        // Already past the target — ratio clamps at 100%, never overshoots.
        await waitFor(() =>
            expect(screen.getByRole('progressbar')).toHaveAttribute(
                'aria-valuenow',
                '100',
            ),
        );
    });

    it('applies the optional className to the root element', () => {
        const { container } = render(
            <MilestoneStrip
                targetSec={1800}
                deltaSec={30}
                currentSec={1830}
                distanceLabel="5K"
                className="relative mt-6"
            />,
        );

        expect(container.firstChild).toHaveClass('relative', 'mt-6');
    });

    it('treats a non-positive currentSec as zero progress', async () => {
        render(
            <MilestoneStrip
                targetSec={1800}
                deltaSec={30}
                currentSec={0}
                distanceLabel="5K"
            />,
        );
        await waitFor(() =>
            expect(screen.getByRole('progressbar')).toHaveAttribute(
                'aria-valuenow',
                '0',
            ),
        );
    });
});
