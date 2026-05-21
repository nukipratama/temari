import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import RunsIndex from './Index';
import { setMockPage } from '@/test/setup';
import type { Activity, ActivityDetail, AnalysisPayload } from '@/types/inertia';

type RunRow = Activity & { detail: ActivityDetail };

const PENDING_RECAP: AnalysisPayload = {
    id: null,
    status: 'pending',
    content: null,
    type: 'weekly_recap',
    subject_type: String.raw`App\Models\WeeklySnapshot`,
    subject_id: 1,
    discriminator: null,
};

const BASE_PROPS = {
    notes: {},
    rangeFilter: '8w' as const,
    rangeStart: '2026-03-26',
    weeklySnapshots: [] as never[],
};

const runFixture: RunRow = {
    id: 1,
    user_id: 1,
    analyzed_at: '2026-05-10',
    detail: {
        id: 11,
        activity_id: 1,
        name: 'Morning Run',
        start_date_local: '2026-05-10T07:00',
        distance: 5000,
        moving_time: 1800,
        average_heartrate: 150,
        trimp_edwards: 60,
    },
};

beforeEach(() => {
    setMockPage({
        auth: { user: { id: 1, name: 'A', first_name: 'A', avatar_url: null } },
        flash: {},
        demoLoginEnabled: false,
    });
});

describe('Runs/Index', () => {
    it('renders empty state when no runs', () => {
        render(<RunsIndex {...BASE_PROPS} runs={[]} />);
        expect(screen.getByText(/Belum ada lari yang tercatat/)).toBeInTheDocument();
    });

    it('renders rows grouped under a week header', () => {
        render(<RunsIndex {...BASE_PROPS} runs={[runFixture]} />);
        expect(screen.getByText('Morning Run')).toBeInTheDocument();
        expect(screen.getAllByText(/Senin/).length).toBeGreaterThan(0);
        expect(screen.getByText(/1 run/)).toBeInTheDocument();
        expect(screen.getByText(/5\.0 km/)).toBeInTheDocument();
    });

    it('skips runs missing detail', () => {
        const orphan = { id: 1, user_id: 1, analyzed_at: '2026-05-10' } as unknown as RunRow;
        render(<RunsIndex {...BASE_PROPS} runs={[orphan]} />);
        expect(screen.queryByRole('link', { name: /run/i })).not.toBeInTheDocument();
    });

    it('renders the range filter chips', () => {
        render(<RunsIndex {...BASE_PROPS} runs={[]} />);
        expect(screen.getByRole('button', { name: '8 minggu', pressed: true })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: '1 tahun', pressed: false })).toBeInTheDocument();
    });

    it('renders weekly stat chips when a matching snapshot exists for the week', () => {
        // Morning Run is 2026-05-10 (Sunday). Week starts Mon 2026-05-04, ends Sun 2026-05-10.
        const snapshot = {
            id: 100,
            week_ending: '2026-05-10',
            distance_km: 18.5,
            runs: 3,
            weekly_trimp: 240,
            atl_7d: 30,
            ctl_42d: 28.4,
            form: 2.1,
            form_status: 'fresh' as const,
            avg_decoupling: 3.5,
            monotony: 1.2,
            strain: 288,
            recap_analysis: PENDING_RECAP,
        };
        render(<RunsIndex {...BASE_PROPS} runs={[runFixture]} weeklySnapshots={[snapshot]} />);
        expect(screen.getByText(/Fit 28\.4/)).toBeInTheDocument();
        // Form +2.1 appears in both the chip and the rule-based fallback prose.
        expect(screen.getAllByText(/Form \+2\.1/).length).toBeGreaterThan(0);
        expect(screen.getAllByText('Segar').length).toBeGreaterThan(0);
    });

    it('renders the note line when a matching note is passed', () => {
        const noteFixture = { ...runFixture, id: 7, detail: { ...runFixture.detail, activity_id: 7 } };
        render(
            <RunsIndex
                {...BASE_PROPS}
                runs={[noteFixture]}
                notes={{ 7: { oneline: 'Solid run, keren tahanin pace-nya.', mood: 'bouncy' } }}
            />,
        );
        expect(screen.getByText('Solid run, keren tahanin pace-nya.')).toBeInTheDocument();
    });

    it('buckets activities without start_date_local under "Tanpa tanggal"', () => {
        const orphan: RunRow = {
            ...runFixture,
            detail: { ...runFixture.detail, start_date_local: null },
        };
        render(<RunsIndex {...BASE_PROPS} runs={[orphan]} />);
        expect(screen.getByText('Tanpa tanggal')).toBeInTheDocument();
    });

});
