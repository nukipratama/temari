import { render, screen, waitFor } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import { makeUser, setMockPage } from '@/test/setup';

import Profile from './Profile';

vi.mock('@/components/profile/JourneyChart', () => ({
    default: () => <div data-testid="journey-chart" />,
}));

const identity = {
    name: 'Ada Lovelace',
    avatar_url: null,
    first_run_at: '2024-08-12',
    member_since: '2024-08-12',
    strava_connected: true,
};

const stats = { total_runs: 63, total_km: 544.1, longest_run_km: 17.99 };

beforeEach(() => {
    setMockPage({
        auth: { user: makeUser() },
        flash: {},
        demoLoginEnabled: false,
    });
});

describe('Profile', () => {
    it('renders the eyebrow and the editorial greeting with the first name', () => {
        render(<Profile identity={identity} stats={stats} />);

        expect(screen.getByText('Profile')).toBeInTheDocument();
        expect(screen.getByText('Ada,')).toBeInTheDocument();
        expect(screen.getByText('your story.')).toBeInTheDocument();
    });

    it('falls back to "Runner," when no first name is available', () => {
        setMockPage({
            auth: { user: null },
            flash: {},
            demoLoginEnabled: false,
        });

        render(<Profile identity={{ ...identity, name: '' }} stats={stats} />);

        expect(screen.getByText('Runner,')).toBeInTheDocument();
    });

    it('renders the lifetime stat tiles in the hero', () => {
        render(<Profile identity={identity} stats={stats} />);

        expect(screen.getByText('Total km')).toBeInTheDocument();
        expect(screen.getByText('544.1')).toBeInTheDocument();
        expect(screen.getByText('Total runs')).toBeInTheDocument();
        expect(screen.getByText('Longest run')).toBeInTheDocument();
    });

    it('renders the time-in-zone bar when the window has zone time', () => {
        render(
            <Profile
                identity={identity}
                stats={stats}
                timeInZone={{ Z1: 15, Z2: 60, Z3: 25 }}
            />,
        );

        expect(
            screen.getByText('Time in zone · last 12 weeks'),
        ).toBeInTheDocument();
        expect(screen.getByText(/Z2 · easy 60%/)).toBeInTheDocument();
    });

    it('omits the time-in-zone bar when no run recorded heart rate', () => {
        render(<Profile identity={identity} stats={stats} />);

        expect(screen.queryByText(/Time in zone/)).not.toBeInTheDocument();
    });

    it('prompts for a race when none is active', () => {
        render(<Profile identity={identity} stats={stats} />);

        expect(screen.getByText('Got a race coming up?')).toBeInTheDocument();
    });

    it('shows the active race from the shared prop', () => {
        setMockPage({
            auth: { user: makeUser() },
            flash: {},
            demoLoginEnabled: false,
            activeRace: {
                id: 1,
                race_date: '2026-10-12',
                distance_m: 21_100,
                goal_time_sec: 6_300,
                name: 'Jakarta Half Marathon',
            },
        });

        render(<Profile identity={identity} stats={stats} />);

        expect(screen.getByText('Jakarta Half Marathon')).toBeInTheDocument();
    });

    it('points at Plan when there is no season', () => {
        render(<Profile identity={identity} stats={stats} />);

        expect(screen.getByText(/No season yet/)).toBeInTheDocument();
    });

    it('renders the season card when a season exists', () => {
        render(
            <Profile
                identity={identity}
                stats={stats}
                season={{
                    starts_at: '2026-06-12',
                    ends_at: '2026-09-04',
                    week_index: 2,
                    total_weeks: 12,
                    goals: [
                        {
                            id: 1,
                            title: 'Honor your rest days',
                            current: 6,
                            target: 12,
                            unit: 'days',
                            is_completed: false,
                        },
                    ],
                }}
                seasonWeeks={[
                    {
                        week_start: '2026-06-15',
                        phase: 'base',
                        type: 'current',
                        planned_km: 30,
                        actual_km: null,
                        sessions: 5,
                    },
                ]}
            />,
        );

        expect(
            screen.getByText('50% · Honor your rest days'),
        ).toBeInTheDocument();
    });

    it('renders the progression card when progressionByCategory is provided', () => {
        render(
            <Profile
                identity={identity}
                stats={stats}
                progressionByCategory={{
                    '5km': {
                        category: '5km',
                        weeks: ['2026-04-13', '2026-04-20', '2026-04-27'],
                        times_sec: [1800, 1770, 1751],
                        goal_sec: 1740,
                    },
                }}
            />,
        );

        expect(screen.getByText(/Journey/)).toBeInTheDocument();
        expect(screen.getByTestId('journey-chart')).toBeInTheDocument();
    });

    it('renders no VDOT, threshold or pace-target rail when fitness is absent', () => {
        render(<Profile identity={identity} stats={stats} />);

        expect(screen.queryByText('VDOT')).not.toBeInTheDocument();
        expect(screen.queryByText('Threshold')).not.toBeInTheDocument();
        expect(screen.queryByText(/pace targets/)).not.toBeInTheDocument();
    });

    it('renders VDOT, threshold and the pace-target rail when fitness is provided', async () => {
        render(
            <Profile
                identity={identity}
                stats={stats}
                fitness={{
                    vdot: 52.3,
                    threshold_pace_sec: 258,
                    threshold_confidence: 'high',
                    training_paces: {
                        easy: 330,
                        marathon: 285,
                        threshold: 258,
                        interval: 240,
                    },
                }}
            />,
        );

        expect(screen.getByText('VDOT')).toBeInTheDocument();
        await waitFor(() =>
            expect(screen.getByText('52.3')).toBeInTheDocument(),
        );
        expect(screen.getByText('Threshold')).toBeInTheDocument();
        expect(
            screen.getByText('Training · pace targets · per km'),
        ).toBeInTheDocument();
        expect(screen.getByText('easy')).toBeInTheDocument();
        expect(screen.getByText('interval')).toBeInTheDocument();
    });

    it('does not show a reconnect CTA when Strava is connected', () => {
        setMockPage({
            auth: { user: makeUser() },
            flash: {},
            demoLoginEnabled: false,
            stravaSync: {
                state: 'ready',
                last_synced_at: '2026-07-04T00:00:00Z',
            },
        });

        render(<Profile identity={identity} stats={stats} />);

        expect(screen.queryByText(/Reconnect/)).not.toBeInTheDocument();
    });

    it('shows a persistent reconnect CTA when the Strava connection is revoked', () => {
        setMockPage({
            auth: { user: makeUser() },
            flash: {},
            demoLoginEnabled: false,
            stravaSync: { state: 'revoked', last_synced_at: null },
        });

        render(<Profile identity={identity} stats={stats} />);

        expect(screen.getByText('Reconnect').closest('a')).toHaveAttribute(
            'href',
            '/auth/strava/redirect?from=/profile',
        );
    });

    it('renders the profile voice quote when profileVoice is provided', () => {
        render(
            <Profile
                identity={identity}
                stats={stats}
                profileVoice={{
                    id: 3,
                    status: 'done',
                    content: "You're getting more consistent every week.",
                    type: 'aku_profile_voice',
                    subject_type: 'aku_profile_voice_user',
                    subject_id: 1,
                    discriminator: '2026-W21',
                }}
            />,
        );

        expect(
            screen.getByText(/getting more consistent every week/),
        ).toBeInTheDocument();
    });

    it('renders no in-page Settings row — the topbar gear replaces it', () => {
        render(<Profile identity={identity} stats={stats} />);

        expect(
            screen.queryByRole('link', { name: 'Settings' }),
        ).not.toBeInTheDocument();
    });
});
