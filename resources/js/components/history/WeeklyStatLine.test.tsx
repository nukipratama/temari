import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import type { WeeklySnapshotWithRecap } from '@/types/inertia';

import { METRIC_GLOSSARY } from '@/lib/metricGlossary';

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
        avg_decoupling: 30,
        avg_decoupling_v2: 3.2,
        monotony: 1.15,
        strain: 486,
        is_current_week: false,
        is_chain_head: false,
        recap_analysis: {
            id: 1,
            status: 'done',
            content: 'ok',
            title: null,
            type: 'weekly_recap',
            subject_type: 'weekly_snapshot',
            subject_id: 1,
            discriminator: null,
        },
        ...overrides,
    };
}

describe('WeeklyStatLine', () => {
    it('names drift and load balance, in one stat line', () => {
        render(<WeeklyStatLine snapshot={snapshot()} />);

        expect(
            screen.getAllByRole('button').map((button) => button.textContent),
        ).toEqual(['drift', 'load balance']);
        expect(screen.getByText('3.2%')).toBeInTheDocument();
        expect(screen.getByText('steady')).toBeInTheDocument();
    });

    it('never shows short-term load or variety, which the recap story carries instead', () => {
        render(<WeeklyStatLine snapshot={snapshot()} />);

        expect(
            screen.queryByRole('button', { name: 'short-term load' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'variety' }),
        ).not.toBeInTheDocument();
        expect(screen.queryByText('73.6')).not.toBeInTheDocument();
        expect(screen.queryByText('1.15')).not.toBeInTheDocument();
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
                    avg_decoupling_v2: null,
                    form_status: null,
                })}
            />,
        );

        expect(container).toBeEmptyDOMElement();
    });

    it('never shows drift when only the frozen legacy field carries a reading', () => {
        render(
            <WeeklyStatLine
                snapshot={snapshot({
                    avg_decoupling: 30,
                    avg_decoupling_v2: null,
                })}
            />,
        );

        expect(
            screen.queryByRole('button', { name: 'drift' }),
        ).not.toBeInTheDocument();
        expect(screen.queryByText('30.0%')).not.toBeInTheDocument();
    });

    it('omits a metric whose value is unknown rather than showing a zero', () => {
        render(<WeeklyStatLine snapshot={snapshot({ form_status: null })} />);

        expect(
            screen.queryByRole('button', { name: 'load balance' }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'drift' }),
        ).toBeInTheDocument();
    });

    it('reveals a plain explanation when a metric word is tapped', () => {
        render(<WeeklyStatLine snapshot={snapshot()} />);

        const drift = screen.getByRole('button', { name: 'drift' });
        expect(drift).toHaveAttribute('aria-expanded', 'false');

        fireEvent.click(drift);

        expect(drift).toHaveAttribute('aria-expanded', 'true');
        expect(
            screen.getByText(METRIC_GLOSSARY.decoupling.body),
        ).toBeInTheDocument();

        fireEvent.click(drift);

        expect(drift).toHaveAttribute('aria-expanded', 'false');
        expect(
            screen.queryByText(METRIC_GLOSSARY.decoupling.body),
        ).not.toBeInTheDocument();
    });

    it('flags drift past its alarm threshold in ember, open by default with a deterministic read', () => {
        render(
            <WeeklyStatLine snapshot={snapshot({ avg_decoupling_v2: 13.4 })} />,
        );

        const drift = screen.getByRole('button', { name: 'drift' });
        expect(drift).toHaveClass('text-ember-ink');
        expect(drift).toHaveAttribute('aria-expanded', 'true');
        expect(screen.getByText(/drift 13.4%:/)).toBeInTheDocument();
        expect(screen.queryByText(/injury/)).not.toBeInTheDocument();
    });

    it('lets a flagged metric be dismissed by tapping it', () => {
        render(
            <WeeklyStatLine snapshot={snapshot({ avg_decoupling_v2: 13.4 })} />,
        );

        const drift = screen.getByRole('button', { name: 'drift' });
        expect(screen.getByText(/drift 13.4%:/)).toBeInTheDocument();

        fireEvent.click(drift);

        expect(drift).toHaveAttribute('aria-expanded', 'false');
        expect(screen.queryByText(/drift 13.4%:/)).not.toBeInTheDocument();
    });

    it.each([
        ['fresh', 'fresh'],
        ['optimal', 'steady'],
        ['fatigued', 'heavy'],
        ['overreaching', 'heavy'],
    ] as const)('shows the form-status word for %s as "%s"', (status, word) => {
        const { unmount } = render(
            <WeeklyStatLine snapshot={snapshot({ form_status: status })} />,
        );
        expect(screen.getByText(word)).toBeInTheDocument();
        unmount();
    });

    it('flags a heavy load balance in ember, open by default with its meaning', () => {
        render(
            <WeeklyStatLine
                snapshot={snapshot({ form_status: 'overreaching' })}
            />,
        );

        const form = screen.getByRole('button', { name: 'load balance' });
        expect(form).toHaveClass('text-ember-ink');
        expect(form).toHaveAttribute('aria-expanded', 'true');
        expect(
            screen.getByText(/above your longer-term load/),
        ).toBeInTheDocument();
    });

    it('does not flag a fresh or steady load balance', () => {
        render(
            <WeeklyStatLine snapshot={snapshot({ form_status: 'fresh' })} />,
        );

        const form = screen.getByRole('button', { name: 'load balance' });
        expect(form).not.toHaveClass('text-ember-ink');
        expect(form).toHaveAttribute('aria-expanded', 'false');
    });

    it('reveals its plain meaning when the form word is tapped', () => {
        render(
            <WeeklyStatLine snapshot={snapshot({ form_status: 'fresh' })} />,
        );

        fireEvent.click(screen.getByRole('button', { name: 'load balance' }));

        expect(
            screen.getByText(/lighter than your longer-term load/),
        ).toBeInTheDocument();
    });
});
