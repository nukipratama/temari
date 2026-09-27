import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import type { WeeklySnapshotWithRecap } from '@/types/inertia';

import WeeklyStatLine from './WeeklyStatLine';

function snapshot(
    overrides: Partial<WeeklySnapshotWithRecap> = {},
): WeeklySnapshotWithRecap {
    return {
        id: 1,
        user_id: 1,
        week_ending: '2026-05-10',
        distance_km: 30,
        runs: 4,
        weekly_trimp: 300,
        atl_7d: 73.6,
        ctl_42d: 42,
        form: -2.5,
        form_status: 'optimal',
        avg_decoupling: 3.2,
        monotony: 1.15,
        strain: 486,
        is_current_week: false,
        is_chain_head: false,
        recap_analysis: {
            id: 1,
            status: 'done',
            content: 'ok',
            type: 'weekly_recap',
            subject_type: 'weekly_snapshot',
            subject_id: 1,
            discriminator: null,
        },
        ...overrides,
    };
}

describe('WeeklyStatLine', () => {
    it('names every metric the week scored, in one stat line', () => {
        render(<WeeklyStatLine snapshot={snapshot()} />);

        expect(
            screen.getByRole('button', { name: 'fatigue' }),
        ).toBeInTheDocument();
        expect(screen.getByText('73.6')).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'variety' }),
        ).toBeInTheDocument();
        expect(screen.getByText('1.15')).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'drift' }),
        ).toBeInTheDocument();
        expect(screen.getByText('3.2%')).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'form' }),
        ).toBeInTheDocument();
        expect(screen.getByText('balanced')).toBeInTheDocument();
    });

    it('never shows load/TRIMP: the week header already carries it', () => {
        render(<WeeklyStatLine snapshot={snapshot()} />);

        expect(
            screen.queryByRole('button', { name: 'load' }),
        ).not.toBeInTheDocument();
    });

    it('renders nothing when the snapshot carries no metrics', () => {
        const { container } = render(
            <WeeklyStatLine
                snapshot={snapshot({
                    atl_7d: null,
                    monotony: null,
                    avg_decoupling: null,
                    form_status: null,
                })}
            />,
        );

        expect(container).toBeEmptyDOMElement();
    });

    it('omits a metric whose value is unknown rather than showing a zero', () => {
        render(<WeeklyStatLine snapshot={snapshot({ form_status: null })} />);

        expect(
            screen.queryByRole('button', { name: 'form' }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'fatigue' }),
        ).toBeInTheDocument();
    });

    it('reveals a plain explanation when a metric word is tapped', () => {
        render(<WeeklyStatLine snapshot={snapshot()} />);

        const fatigue = screen.getByRole('button', { name: 'fatigue' });
        expect(fatigue).toHaveAttribute('aria-expanded', 'false');

        fireEvent.click(fatigue);

        expect(fatigue).toHaveAttribute('aria-expanded', 'true');
        expect(
            screen.getByText(/your training load over the last 7 days/),
        ).toBeInTheDocument();

        fireEvent.click(fatigue);

        expect(fatigue).toHaveAttribute('aria-expanded', 'false');
        expect(
            screen.queryByText(/your training load over the last 7 days/),
        ).not.toBeInTheDocument();
    });

    it('flags a metric past its alarm threshold in ember, open by default with a deterministic read', () => {
        render(
            <WeeklyStatLine
                snapshot={snapshot({ monotony: 1.8, avg_decoupling: 9.4 })}
            />,
        );

        const variety = screen.getByRole('button', { name: 'variety' });
        const drift = screen.getByRole('button', { name: 'drift' });
        expect(variety).toHaveClass('text-ember-ink');
        expect(drift).toHaveClass('text-ember-ink');
        expect(variety).toHaveAttribute('aria-expanded', 'true');
        expect(drift).toHaveAttribute('aria-expanded', 'true');

        expect(screen.getByText(/variety 1.80:/)).toBeInTheDocument();
        expect(screen.getByText(/drift 9.4%:/)).toBeInTheDocument();
    });

    it('lets a flagged metric be dismissed by tapping it', () => {
        render(<WeeklyStatLine snapshot={snapshot({ avg_decoupling: 9.4 })} />);

        const drift = screen.getByRole('button', { name: 'drift' });
        expect(screen.getByText(/drift 9.4%:/)).toBeInTheDocument();

        fireEvent.click(drift);

        expect(drift).toHaveAttribute('aria-expanded', 'false');
        expect(screen.queryByText(/drift 9.4%:/)).not.toBeInTheDocument();
    });

    it('does not flag fatigue, which has no alarm threshold', () => {
        render(<WeeklyStatLine snapshot={snapshot()} />);

        expect(screen.getByRole('button', { name: 'fatigue' })).not.toHaveClass(
            'text-ember-ink',
        );
    });

    it.each([
        ['fresh', 'fresh'],
        ['optimal', 'balanced'],
        ['fatigued', 'tired'],
        ['overreaching', 'overreaching'],
    ] as const)('shows the form-status word for %s as "%s"', (status, word) => {
        const { unmount } = render(
            <WeeklyStatLine snapshot={snapshot({ form_status: status })} />,
        );
        expect(screen.getByText(word)).toBeInTheDocument();
        unmount();
    });

    it('flags an overreaching form status in ember, open by default with its meaning', () => {
        render(
            <WeeklyStatLine
                snapshot={snapshot({ form_status: 'overreaching' })}
            />,
        );

        const form = screen.getByRole('button', { name: 'form' });
        expect(form).toHaveClass('text-ember-ink');
        expect(form).toHaveAttribute('aria-expanded', 'true');
        expect(
            screen.getByText(/piled well past your six-week average/),
        ).toBeInTheDocument();
    });

    it('does not flag a non-overreaching form status', () => {
        render(
            <WeeklyStatLine snapshot={snapshot({ form_status: 'fresh' })} />,
        );

        const form = screen.getByRole('button', { name: 'form' });
        expect(form).not.toHaveClass('text-ember-ink');
        expect(form).toHaveAttribute('aria-expanded', 'false');
    });

    it('reveals its plain meaning when the form word is tapped', () => {
        render(
            <WeeklyStatLine snapshot={snapshot({ form_status: 'fresh' })} />,
        );

        fireEvent.click(screen.getByRole('button', { name: 'form' }));

        expect(
            screen.getByText(
                /the last week has been lighter than your six-week average/,
            ),
        ).toBeInTheDocument();
    });
});
