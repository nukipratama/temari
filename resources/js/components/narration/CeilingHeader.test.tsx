import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import type { Budget } from '@/pages/Narration/types';

import { formMock } from '@/test/setup';

import CeilingHeader from './CeilingHeader';

const BUDGET: Budget = {
    todayCost: 1.25,
    dailyCeiling: 3,
    perUserCeiling: 1,
    totalCeiling: 5,
    athletes: 3,
    currency: 'USD',
    trippedAt: null,
    degradedFills: 0,
};

function renderHeader(
    budget: Partial<Budget> = {},
    cappedToday = 0,
    pauseReason: string | null = null,
) {
    return render(
        <CeilingHeader
            budget={{ ...BUDGET, ...budget }}
            cappedToday={cappedToday}
            pauseReason={pauseReason}
        />,
    );
}

describe('CeilingHeader', () => {
    it('reads today against the app-wide ceiling, not the derived combined figure', () => {
        renderHeader();

        expect(screen.getByText('$1.25')).toBeInTheDocument();
        expect(screen.getByText('/ $5.00')).toBeInTheDocument();
    });

    it('says the share in words, since a near-empty bar reads as nothing', () => {
        renderHeader();

        expect(
            screen.getByText('25% of the app-wide ceiling'),
        ).toBeInTheDocument();
    });

    it('says so plainly when no app-wide ceiling is configured', () => {
        renderHeader({ totalCeiling: null });

        expect(
            screen.getByText('No app-wide ceiling set.'),
        ).toBeInTheDocument();
    });

    it('counts the athletes already capped today', () => {
        renderHeader({}, 2);

        expect(screen.getByText('2 capped today')).toBeInTheDocument();
    });

    it('reports generation as running when nothing blocks a dispatch', () => {
        renderHeader();

        expect(screen.getByText('generating')).toBeInTheDocument();
    });

    it('translates the pause reason instead of leaking the raw token', () => {
        renderHeader({}, 0, 'kill_switch');

        expect(screen.getByText('paused: kill switch off')).toBeInTheDocument();
    });

    it('shows an unmapped pause reason as-is rather than hiding it', () => {
        renderHeader({}, 0, 'something_new');

        expect(screen.getByText('paused: something_new')).toBeInTheDocument();
    });

    it('reports the trip time and how much was served rule-based because of it', () => {
        renderHeader({
            trippedAt: '2026-09-10T14:32:00+07:00',
            degradedFills: 4,
        });

        expect(
            screen.getByText('tripped 14:32 · 4 served rule-based'),
        ).toBeInTheDocument();
    });

    it('posts the one-shot recovery', () => {
        renderHeader();

        fireEvent.click(screen.getByRole('button', { name: 'recover all' }));

        expect(formMock.post).toHaveBeenCalledWith(
            '/devtools/narration/recover',
            expect.anything(),
        );
    });

    it('disables the recover button while it is in flight', () => {
        formMock.processing = true;
        renderHeader();

        expect(
            screen.getByRole('button', { name: 'recover all' }),
        ).toBeDisabled();
    });
});
