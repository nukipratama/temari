import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import PaceTargetsCard from './PaceTargetsCard';

const PACES = { easy: 370, marathon: 320, threshold: 292, interval: 268 };

describe('PaceTargetsCard', () => {
    it('draws all four targets with their formatted pace', () => {
        render(<PaceTargetsCard paces={PACES} />);

        expect(screen.getByText('Easy')).toBeInTheDocument();
        expect(screen.getByText('6:10')).toBeInTheDocument();
        expect(screen.getByText('Marathon')).toBeInTheDocument();
        expect(screen.getByText('Tempo')).toBeInTheDocument();
        expect(screen.getByText('Interval')).toBeInTheDocument();
        expect(screen.getByText('4:28')).toBeInTheDocument();
    });

    it('anchors the slowest pace at the left of the rail and the fastest at the right', () => {
        const { container } = render(<PaceTargetsCard paces={PACES} />);
        const markers =
            container.querySelectorAll<HTMLElement>('[style*="left"]');

        expect(markers[0].style.left).toBe('0%');
        expect(markers[3].style.left).toBe('100%');
    });

    it('centres every marker when all four paces are identical', () => {
        const { container } = render(
            <PaceTargetsCard
                paces={{
                    easy: 300,
                    marathon: 300,
                    threshold: 300,
                    interval: 300,
                }}
            />,
        );

        for (const marker of container.querySelectorAll<HTMLElement>(
            '[style*="left"]',
        )) {
            expect(marker.style.left).toBe('50%');
        }
    });
});
