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

    it('shifts each label by its own offset so the end ones stay inside the rail', () => {
        const { container } = render(<PaceTargetsCard paces={PACES} />);
        const labels = container.querySelectorAll<HTMLElement>(
            'span[style*="left"]',
        );

        expect(labels).toHaveLength(4);
        for (const label of labels) {
            expect(label.style.transform).toBe(
                `translateX(-${label.style.left})`,
            );
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
