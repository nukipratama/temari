import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import type { DailyRow } from '@/pages/AiUsage/types';

import DailyChart from './DailyChart';

function day(dayKey: string, total: number, cost = 0.01): DailyRow {
    return { day: dayKey, prompt: total, completion: 0, total, calls: 1, cost };
}

const twoDays = [day('2026-05-18', 450, 0.03), day('2026-05-19', 430, 0.02)];

function manyDays(count: number): DailyRow[] {
    return Array.from({ length: count }, (_, i) =>
        day(`2026-05-${String(i + 1).padStart(2, '0')}`, 100),
    );
}

describe('DailyChart', () => {
    it('counts the days in view and sums their estimated cost', () => {
        render(<DailyChart data={twoDays} currency="USD" />);

        expect(screen.getByText('2 days')).toBeInTheDocument();
        expect(screen.getByText('$0.05')).toBeInTheDocument();
    });

    it('labels each bar with its day and token total for screen readers', () => {
        render(<DailyChart data={twoDays} currency="USD" />);

        expect(screen.getByLabelText('may 18: 450 tokens')).toBeInTheDocument();
        expect(screen.getByLabelText('may 19: 430 tokens')).toBeInTheDocument();
    });

    it('scales the tallest bar to full height and the rest against it', () => {
        render(<DailyChart data={twoDays} currency="USD" />);

        expect(screen.getByLabelText('may 18: 450 tokens').style.height).toBe(
            '100%',
        );
        expect(
            screen.getByLabelText('may 19: 430 tokens').style.height,
        ).not.toBe('100%');
    });

    it('keeps a zero-token day visible as a floor-height bar', () => {
        render(
            <DailyChart
                data={[day('2026-05-18', 450), day('2026-05-19', 0)]}
                currency="USD"
            />,
        );

        expect(screen.getByLabelText('may 19: 0 tokens').style.height).toBe(
            '2%',
        );
    });

    it('uses the short weekday axis label for a window of 14 days or fewer', () => {
        render(<DailyChart data={twoDays} currency="USD" />);

        expect(screen.getByText('18 mon')).toBeInTheDocument();
    });

    it('falls back to a truncated day-month axis label with a title tooltip past 14 days', () => {
        const { container } = render(
            <DailyChart data={manyDays(15)} currency="USD" />,
        );

        expect(
            screen.queryByText(/^\d+ (Mon|Tue|Wed|Thu|Fri|Sat|Sun)$/),
        ).not.toBeInTheDocument();

        const axisLabels = container.querySelectorAll('span[title]');
        expect(axisLabels).toHaveLength(15);
        expect(axisLabels[0].textContent).toBe('may 1');
        expect(axisLabels[0].getAttribute('title')).toBe('may 1');
    });
});
