import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it } from 'vitest';

import type {
    ActivityDetail,
    BriefingResult,
    TrainingLoad,
    WeeklySnapshot,
} from '@/types/inertia';

import { makeUser, setMockPage } from '@/test/setup';

import WeekStatsDisclosure from './WeekStatsDisclosure';

const briefing: BriefingResult = {
    vibeState: 'steady',
    vibeLabel: 'Steady',
    vibeEmoji: '🙂',
    mascotVoice: {
        id: 1,
        status: 'done',
        content: 'x',
        type: 'briefing_mascot_voice',
        subject_type: 'briefing_user_day',
        subject_id: 1,
        discriminator: '2026-06-12',
    },
    recoveryLabel: 'Recovery: 14h',
    recoveryTone: 'warning',
    recoveryHoursLabel: '14h',
    recoveryHours: 14,
    streakLabel: null,
    sigilPattern: 'orct',
    accessory: null,
    mood: 'easy',
};

const load: TrainingLoad = {
    form: 8,
    form_status: 'optimal',
    ctl_42d: 42,
    atl_7d: 38,
    weekly_trimp: 312,
    monotony: 1.1,
    strain: 384,
};

const snapshot: WeeklySnapshot = {
    id: 1,
    user_id: 1,
    week_ending: '2026-06-14',
    runs: 4,
    distance_km: 18.2,
    weekly_trimp: 312,
    ctl_42d: 42,
    atl_7d: 38,
    form: 4,
    form_status: 'optimal',
    avg_decoupling: 3.2,
    monotony: 1.1,
    strain: 384,
};

const lastRun: ActivityDetail = {
    id: 1,
    activity_id: 99,
    name: 'Morning six',
    start_date_local: '2026-06-12T07:00',
    distance: 6200,
    elapsed_time: 2059,
    average_heartrate: 152,
    trimp_edwards: 78,
};

function renderDisclosure(
    overrides: Partial<{ lastRun: ActivityDetail | null }> = {},
) {
    return render(
        <WeekStatsDisclosure
            briefing={briefing}
            load={load}
            snapshot={snapshot}
            lastRun={
                overrides.lastRun === undefined ? lastRun : overrides.lastRun
            }
        />,
    );
}

beforeEach(() => {
    setMockPage({
        auth: { user: makeUser() },
        flash: {},
        demoLoginEnabled: false,
    });
});

describe('WeekStatsDisclosure', () => {
    // The prototype passes no `defaultOpen` (TodayScreen.tsx:464), and the
    // 2026-08-31 amendment settled closed as what ships.
    it('renders closed, summarising the week on its trigger', async () => {
        renderDisclosure();

        const trigger = screen.getByRole('button');
        expect(trigger).toHaveAttribute('aria-expanded', 'false');
        await waitFor(() => {
            expect(trigger).toHaveTextContent(
                /This week's stats · 4 runs · 18\.2 km/,
            );
        });
        expect(screen.queryByText('trimp')).not.toBeInTheDocument();
        expect(screen.queryByText('Steady')).not.toBeInTheDocument();
    });

    it('reveals the stat strip, vital bars and both mini cards when opened', async () => {
        renderDisclosure();

        await userEvent.click(screen.getByRole('button'));

        // Once in the stat strip, once in the last-run mini card.
        expect(screen.getAllByText('trimp')).toHaveLength(2);
        expect(screen.getByText('312')).toBeInTheDocument();
        expect(screen.getByText('Steady')).toBeInTheDocument();
        expect(screen.getByText(/^Last run · /)).toBeInTheDocument();
        expect(screen.getByText(/^Condition · /)).toBeInTheDocument();
    });

    it('drops the last-run card when there is no run to show', async () => {
        renderDisclosure({ lastRun: null });

        await userEvent.click(screen.getByRole('button'));

        expect(screen.queryByText(/^Last run · /)).not.toBeInTheDocument();
        expect(screen.getByText(/^Condition · /)).toBeInTheDocument();
    });

    it('reads every figure as unknown when no snapshot has landed yet', async () => {
        render(
            <WeekStatsDisclosure
                briefing={briefing}
                load={load}
                snapshot={null}
                lastRun={null}
            />,
        );

        await userEvent.click(screen.getByRole('button'));

        // Three stat figures, all unknown: never a zero.
        expect(screen.getAllByText('—').length).toBeGreaterThanOrEqual(3);
    });

    it('leaves the weekly TRIMP figure unknown when nothing that week scored', async () => {
        render(
            <WeekStatsDisclosure
                briefing={briefing}
                load={load}
                snapshot={{ ...snapshot, weekly_trimp: null }}
                lastRun={null}
            />,
        );

        await userEvent.click(screen.getByRole('button'));

        const trimpFigure = screen.getAllByText('trimp')[0].parentElement;
        expect(trimpFigure?.textContent).toMatch(/—/);
        expect(trimpFigure?.textContent).not.toMatch(/\d/);
    });
});
