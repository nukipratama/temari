import type { ComponentProps } from 'react';

import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import type {
    AnalysisPayload,
    BriefingResult,
    TrainingLoad,
    WeeklySnapshot,
} from '@/types/inertia';

import { setMockDeferred } from '@/test/setup';

import Trends from './Trends';

function narrationPayload(
    discriminator: '30d' | '90d' | '12mo',
    content: string,
): AnalysisPayload {
    return {
        id: 1,
        status: 'done',
        content,
        type: 'trend_read',
        is_zone_dependent: true,
        subject_type: 'trend_read_user_range',
        subject_id: 1,
        discriminator,
    };
}

const NARRATION = {
    '30d': narrationPayload('30d', 'Last 30 days.\n\nFitness climbing.'),
    '90d': narrationPayload('90d', 'Last 90 days.\n\nSteady build.'),
    '12mo': narrationPayload('12mo', 'The full year.\n\nA long climb.'),
};

const briefing: BriefingResult = {
    vibeState: 'pumped',
    vibeLabel: 'Pumped',
    vibeEmoji: '💥',
    firstRead: false,
    mascotVoice: {
        id: 4,
        status: 'done',
        content: 'Easy 6k.',
        type: 'briefing_mascot_voice',
        subject_type: 'briefing_user_day',
        subject_id: 1,
        discriminator: '2026-06-12',
    },
    recoveryLabel: 'Recovery: 41h',
    recoveryTone: 'positive',
    recoveryHoursLabel: '41h',
    recoveryHours: 41,
    streakLabel: 'Ran today',
    sigilPattern: 'orct',
    mood: 'blazing',
};

const load: TrainingLoad = {
    form: -2.5,
    form_status: 'optimal',
    ctl_42d: 42,
    atl_7d: 44.5,
    weekly_trimp: 320,
    monotony: 1.2,
    strain: 384,
};

const snapshot: WeeklySnapshot = {
    id: 1,
    user_id: 1,
    week_ending: '2026-06-14',
    runs: 4,
    distance_km: 35.5,
    weekly_trimp: 280,
    ctl_42d: 42,
    atl_7d: 44.5,
    form: -2.5,
    form_status: 'optimal',
    avg_decoupling: 3.2,
    monotony: 1.4,
    strain: 392,
};

const BASE_PROPS: ComponentProps<typeof Trends> = {
    briefing,
    load,
    snapshot,
    ctlTrend: [],
    badgeMilestones: [],
    streak: {
        weeks: 0,
        rest_weeks_held: 0,
        rest_weeks_cap: 2,
        ran_this_week: false,
        week_ends_on: '2026-08-30',
    },
    narration: NARRATION,
};

/** A year of daily points, so every range window has data to slice. */
function yearOfTrend() {
    return Array.from({ length: 365 }, (_, i) => ({
        date: `2026-01-${String((i % 28) + 1).padStart(2, '0')}`,
        ctl: 40 + i * 0.05,
        atl: 35,
    }));
}

describe('Trends', () => {
    it('renders the page headline', () => {
        render(<Trends {...BASE_PROPS} />);

        expect(screen.getByText('how things')).toBeInTheDocument();
        expect(screen.getByText('are going.')).toBeInTheDocument();
    });

    it('renders the load section under the hero, above the range tabs', () => {
        const { container } = render(<Trends {...BASE_PROPS} />);

        const eyebrow = screen.getByText('load');
        expect(screen.getByText('Pumped')).toBeInTheDocument();
        expect(screen.getByText('Condition · 7 days')).toBeInTheDocument();
        expect(
            eyebrow.compareDocumentPosition(
                screen.getByRole('group', { name: 'Time range' }),
            ) & Node.DOCUMENT_POSITION_FOLLOWING,
        ).toBeTruthy();
        expect(container).toContainElement(eyebrow);
    });

    it('holds the load section back behind a skeleton until its props land', () => {
        setMockDeferred(['briefing', 'load', 'snapshot']);

        render(<Trends {...BASE_PROPS} />);

        expect(screen.queryByText('load')).not.toBeInTheDocument();
        expect(screen.queryByText('Pumped')).not.toBeInTheDocument();
    });

    it('renders exactly the four prototype blocks', () => {
        render(<Trends {...BASE_PROPS} />);

        expect(screen.getByText('Trends')).toBeInTheDocument();
        expect(
            screen.getByRole('group', { name: 'Time range' }),
        ).toBeInTheDocument();
        expect(screen.getByText("Temari's read")).toBeInTheDocument();
        expect(
            screen.getByText(/not enough training history yet/),
        ).toBeInTheDocument();
    });

    it('opens on the 30 day range, the one narrated daily', () => {
        render(<Trends {...BASE_PROPS} />);

        expect(screen.getByText('Last 30 days.')).toBeInTheDocument();
        expect(screen.queryByText('The full year.')).not.toBeInTheDocument();
    });

    it('switches the narration shown when the range toggle changes', () => {
        render(<Trends {...BASE_PROPS} />);

        fireEvent.click(screen.getByRole('button', { name: '12 months' }));

        expect(screen.getByText('The full year.')).toBeInTheDocument();
        expect(screen.queryByText('Last 30 days.')).not.toBeInTheDocument();
    });

    it('re-windows the fitness panel when the range toggle changes', () => {
        render(<Trends {...BASE_PROPS} ctlTrend={yearOfTrend()} />);

        expect(
            screen.getByRole('img', { name: /over 30 days/ }),
        ).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: '12 months' }));

        expect(
            screen.getByRole('img', { name: /over 365 days/ }),
        ).toBeInTheDocument();
    });

    it('shows skeletons for the deferred blocks until their props land', () => {
        setMockDeferred([
            'narration',
            'ctlTrend',
            'badgeMilestones',
            'streak',
            'briefing',
            'load',
            'snapshot',
        ]);

        const { container } = render(<Trends />);

        expect(screen.getByText('how things')).toBeInTheDocument();
        expect(
            screen.getByRole('group', { name: 'Time range' }),
        ).toBeInTheDocument();
        expect(screen.queryByText("Temari's read")).not.toBeInTheDocument();
        expect(container.querySelectorAll('.skeleton').length).toBeGreaterThan(
            0,
        );
    });

    it('fills the fitness block in on its own once narration is still pending', () => {
        setMockDeferred(['narration']);

        render(<Trends {...BASE_PROPS} ctlTrend={yearOfTrend()} />);

        expect(screen.queryByText('Last 30 days.')).not.toBeInTheDocument();
        expect(
            screen.getByRole('img', { name: /over 30 days/ }),
        ).toBeInTheDocument();
    });

    it('shows the week streak as a chip inside the fitness panel', () => {
        render(
            <Trends
                {...BASE_PROPS}
                ctlTrend={yearOfTrend()}
                streak={{
                    weeks: 6,
                    rest_weeks_held: 0,
                    rest_weeks_cap: 2,
                    ran_this_week: true,
                    week_ends_on: '2026-08-30',
                }}
            />,
        );

        expect(
            screen.getByRole('button', { name: /6-week streak/ }),
        ).toBeInTheDocument();
    });
});
