import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import PaceTargetsCard from './PaceTargetsCard';

const PACES = { easy: 370, marathon: 320, threshold: 292, interval: 268 };

describe('PaceTargetsCard', () => {
    it('draws all four targets with their formatted pace', () => {
        render(<PaceTargetsCard paces={PACES} />);

        expect(screen.getByText('easy')).toBeInTheDocument();
        expect(screen.getByText('6:10')).toBeInTheDocument();
        expect(screen.getByText('marathon')).toBeInTheDocument();
        expect(screen.getByText('tempo')).toBeInTheDocument();
        expect(screen.getByText('interval')).toBeInTheDocument();
        expect(screen.getByText('4:28')).toBeInTheDocument();
    });

    it('anchors the slowest pace at the left of the rail and the fastest at the right', () => {
        const { container } = render(<PaceTargetsCard paces={PACES} />);
        const dots =
            container.querySelectorAll<HTMLElement>('i[style*="left"]');

        expect(dots[0].style.left).toBe('0%');
        expect(dots[3].style.left).toBe('100%');
    });

    it('anchors the end labels to the rail but centres the ones between', () => {
        const { container } = render(<PaceTargetsCard paces={PACES} />);
        const labels = container.querySelectorAll<HTMLElement>(
            'span[style*="left"]',
        );

        const shiftOf = (label: HTMLElement): number =>
            Number(label.style.transform.replace(/[^\d.]/g, ''));

        expect(labels).toHaveLength(4);
        // Flush left at 0% and flush right at 100%, so neither overhangs.
        expect(shiftOf(labels[0])).toBe(0);
        expect(shiftOf(labels[3])).toBe(100);
        // The two between sit essentially over their own dots. Anchoring each
        // label by its own offset — the shape this replaced — would have put
        // these at 49 and 76, drifting further off the dot the wider the rail.
        for (const label of [labels[1], labels[2]]) {
            expect(shiftOf(label)).toBeGreaterThan(45);
            expect(shiftOf(label)).toBeLessThan(55);
        }
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
