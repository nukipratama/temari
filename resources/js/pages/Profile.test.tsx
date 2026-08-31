import {
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import { makeUser, setMockPage } from '@/test/setup';

import Profile from './Profile';

vi.mock('@/components/collection/ProgressionChart', () => ({
    default: () => <div data-testid="progression-chart" />,
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
    it('renders the editorial greeting with the first name', () => {
        render(<Profile identity={identity} stats={stats} />);
        expect(screen.getByText(/running since/i)).toBeInTheDocument();
        expect(screen.getByText('Ada runner,')).toBeInTheDocument();
    });

    it('falls back to "runner," when no first name is available', () => {
        setMockPage({
            auth: { user: null },
            flash: {},
            demoLoginEnabled: false,
        });
        const noFirstName = { ...identity, name: '' };
        render(<Profile identity={noFirstName} stats={stats} />);
        expect(screen.getByText('runner,')).toBeInTheDocument();
    });

    it('renders no in-page Me nav — the topbar gear replaces it', () => {
        render(<Profile identity={identity} stats={stats} />);
        expect(
            screen.queryByRole('link', { name: 'Settings' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('link', { name: 'Accessories' }),
        ).not.toBeInTheDocument();
    });

    it('renders the three stat tiles', () => {
        render(<Profile identity={identity} stats={stats} />);
        expect(screen.getByText('Total km')).toBeInTheDocument();
        expect(screen.getByText('Total runs')).toBeInTheDocument();
        expect(screen.getByText('Longest run')).toBeInTheDocument();
    });

    // Settings is reached from the shell topbar's gear on this screen, not
    // from a row in the page body. That entry point is asserted in
    // MeTabs' replacement, MobileTopBar.test.tsx.
    it('no longer carries a settings row of its own', () => {
        render(<Profile identity={identity} stats={stats} />);
        expect(
            screen.queryByText(/Notifikasi Telegram, zona HR/),
        ).not.toBeInTheDocument();
    });

    it('renders the season & streak panel when the server hands it a seasonStreak prop', () => {
        render(
            <Profile
                identity={identity}
                stats={stats}
                seasonStreak={{
                    season: null,
                    streak: {
                        weeks: 0,
                        rest_weeks_held: 0,
                        rest_weeks_cap: 2,
                        weeks_to_next_rest_week: 3,
                        ran_this_week: false,
                        week_ends_on: '2026-08-16',
                        last_forgiven_week: null,
                    },
                }}
            />,
        );
        expect(screen.getByText('Season & streak')).toBeInTheDocument();
    });

    it('omits the season & streak panel when no seasonStreak prop is given', () => {
        render(<Profile identity={identity} stats={stats} />);
        expect(screen.queryByText('Season & streak')).not.toBeInTheDocument();
    });

    it('shows the "With Temari since" join date when member_since is present', () => {
        render(<Profile identity={identity} stats={stats} />);
        expect(screen.getByText('With Temari since')).toBeInTheDocument();
        expect(screen.getByText('12 Aug 2024')).toBeInTheDocument();
    });

    it('omits the join-date block when member_since is missing', () => {
        render(
            <Profile
                identity={{ ...identity, member_since: null }}
                stats={stats}
            />,
        );
        expect(screen.queryByText('With Temari since')).not.toBeInTheDocument();
    });

    it('renders the progression section when progressionByCategory is provided', () => {
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
        expect(screen.getByTestId('progression-chart')).toBeInTheDocument();
    });

    it('switches the progression chart between distances when more than one category is present', () => {
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
                    '10km': {
                        category: '10km',
                        weeks: ['2026-04-13', '2026-04-20', '2026-04-27'],
                        times_sec: [3800, 3770, 3751],
                        goal_sec: 3740,
                    },
                }}
            />,
        );

        const tablist = screen.getByRole('tablist', {
            name: 'Choose distance',
        });
        expect(
            within(tablist).getByRole('tab', { name: '5K' }),
        ).toHaveAttribute('aria-selected', 'false');

        fireEvent.click(within(tablist).getByRole('tab', { name: '5K' }));

        expect(
            within(tablist).getByRole('tab', { name: '5K' }),
        ).toHaveAttribute('aria-selected', 'true');
    });

    it('renders no VDOT, threshold pace or Training pace block when fitness is absent', () => {
        render(<Profile identity={identity} stats={stats} />);
        expect(screen.queryByText('VDOT')).not.toBeInTheDocument();
        expect(screen.queryByText('Threshold pace')).not.toBeInTheDocument();
        expect(screen.queryByText(/Training/)).not.toBeInTheDocument();
    });

    it('renders VDOT, threshold pace and the Training pace-target block when fitness is provided', async () => {
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
        // VDOT counts up from 0, so its target text settles asynchronously.
        await waitFor(() =>
            expect(screen.getByText('52.3')).toBeInTheDocument(),
        );
        expect(screen.getByText('Threshold pace')).toBeInTheDocument();
        expect(screen.getByText(/Training/)).toBeInTheDocument();
        expect(screen.getByText('Easy')).toBeInTheDocument();
        expect(screen.getByText('Marathon')).toBeInTheDocument();
        expect(screen.getByText('Tempo')).toBeInTheDocument();
        expect(screen.getByText('Interval')).toBeInTheDocument();
    });

    // The Strava zone reconnect banner is shell chrome, not page content: it
    // lives in AppShell, which is now a persistent layout rather than something
    // this page renders. Its own behaviour is covered by
    // StravaZoneReconnectBanner.test.tsx, and AppShell.test.tsx proves the shell
    // mounts it.

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
        const link = screen.getByText('Reconnect').closest('a');
        expect(link).toHaveAttribute(
            'href',
            '/auth/strava/redirect?from=/profile',
        );
    });

    it('renders the profile voice quote when profileVoice is provided', () => {
        const profileVoice = {
            id: 3,
            status: 'done' as const,
            content: "You're getting more consistent every week.",
            type: 'aku_profile_voice' as const,
            subject_type: 'aku_profile_voice_user',
            subject_id: 1,
            discriminator: '2026-W21',
        };
        render(
            <Profile
                identity={identity}
                stats={stats}
                profileVoice={profileVoice}
            />,
        );
        expect(
            screen.getByText(/getting more consistent every week/),
        ).toBeInTheDocument();
    });
});
