import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import ConsistencyLine from './ConsistencyLine';

describe('ConsistencyLine', () => {
    it('renders the full consistency sentence', () => {
        render(
            <ConsistencyLine
                stats={{
                    runs: 22,
                    km: 194,
                    ranDays: 21,
                    daysInMonth: 30,
                    longestStreak: 10,
                }}
            />,
        );
        const text = screen.getByText(
            (_, el) => el?.tagName === 'P',
        )?.textContent;
        expect(text).toBe(
            '22 runs · 194 km · ran 21/30 days · longest streak 10',
        );
    });

    it('pluralizes a single run correctly', () => {
        render(
            <ConsistencyLine
                stats={{
                    runs: 1,
                    km: 5,
                    ranDays: 1,
                    daysInMonth: 30,
                    longestStreak: 1,
                }}
            />,
        );
        expect(screen.getByText(/1 run ·/)).toBeInTheDocument();
    });

    it('never starts or ends a wrap group on a dangling dot', () => {
        const { container } = render(
            <ConsistencyLine
                stats={{
                    runs: 0,
                    km: 0,
                    ranDays: 0,
                    daysInMonth: 30,
                    longestStreak: 0,
                }}
            />,
        );
        const groups = container.querySelectorAll('span.whitespace-nowrap');
        expect(groups).toHaveLength(2);
        for (const group of groups) {
            const text = group.textContent?.trim() ?? '';
            expect(text.startsWith('·')).toBe(false);
            expect(text.endsWith('·')).toBe(false);
        }
    });
});
