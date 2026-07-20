import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import Aku from './Aku';
import { makeUser, setMockPage } from '@/test/setup';

vi.mock('@/components/koleksi/ProgressionChart', () => ({
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

describe('Aku', () => {
    it('renders the editorial greeting with the first name', () => {
        render(<Aku identity={identity} stats={stats} />);
        expect(screen.getByText(/berlari sejak/i)).toBeInTheDocument();
        expect(screen.getByText('Ada Runner,')).toBeInTheDocument();
    });

    it('falls back to "Aku," when no first name is available', () => {
        setMockPage({ auth: { user: null }, flash: {}, demoLoginEnabled: false });
        const noFirstName = { ...identity, name: '' };
        render(<Aku identity={noFirstName} stats={stats} />);
        expect(screen.getByText('Aku,')).toBeInTheDocument();
    });

    it('renders the three stat tiles', () => {
        render(<Aku identity={identity} stats={stats} />);
        expect(screen.getByText('Total km')).toBeInTheDocument();
        expect(screen.getByText('Total lari')).toBeInTheDocument();
        expect(screen.getByText('Lari terjauh')).toBeInTheDocument();
    });

    it('links to the settings hub', () => {
        render(<Aku identity={identity} stats={stats} />);
        expect(screen.getByText(/Notifikasi Telegram, zona HR/).closest('a')).toHaveAttribute('href', '/pengaturan');
    });

    it('renders the persona bar + summary when personaMix is provided', () => {
        const mix = [
            { mood: 'enteng' as const, count: 22, percent: 34.9 },
            { mood: 'adem' as const, count: 21, percent: 33.3 },
        ];
        const summary = {
            id: 1,
            status: 'done' as const,
            content: 'Larimu konsisten dan pintar menjaga ritme.',
            type: 'persona_summary' as const,
            subject_type: 'persona_summary_user',
            subject_id: 1,
            discriminator: null,
        };
        render(<Aku identity={identity} stats={stats} personaMix={mix} personaSummary={summary} />);
        expect(screen.getByText(/Persona/)).toBeInTheDocument();
        expect(screen.getByText(/konsisten dan pintar/)).toBeInTheDocument();
    });

    it('renders the progression section when progressionByCategory is provided', () => {
        render(
            <Aku
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
        expect(screen.getByText(/Perjalanan/)).toBeInTheDocument();
        expect(screen.getByTestId('progression-chart')).toBeInTheDocument();
    });

    it('renders VDOT and threshold pace in the hero when fitness data is provided', () => {
        render(
            <Aku
                identity={identity}
                stats={stats}
                fitness={{ vdot: 42.1, threshold_pace_sec: 300, threshold_confidence: 'high' }}
            />,
        );
        expect(screen.getByText('VDOT')).toBeInTheDocument();
        expect(screen.getByText('42.1')).toBeInTheDocument();
        expect(screen.getByText('Threshold pace')).toBeInTheDocument();
    });

    it('renders the Latihan pace block when training_paces is provided', () => {
        render(
            <Aku
                identity={identity}
                stats={stats}
                fitness={{
                    vdot: 42.1,
                    threshold_pace_sec: 300,
                    threshold_confidence: 'high',
                    training_paces: { easy: 390, marathon: 330, threshold: 300, interval: 270 },
                }}
            />,
        );
        expect(screen.getByText(/Latihan/)).toBeInTheDocument();
        expect(screen.getByText('Easy')).toBeInTheDocument();
        expect(screen.getByText('Marathon')).toBeInTheDocument();
        expect(screen.getByText('Interval')).toBeInTheDocument();
        expect(screen.getByText('6:30')).toBeInTheDocument();
    });

    it('omits the Latihan pace block when training_paces is absent', () => {
        render(
            <Aku
                identity={identity}
                stats={stats}
                fitness={{ vdot: 42.1, threshold_pace_sec: 300, threshold_confidence: 'high', training_paces: null }}
            />,
        );
        expect(screen.queryByText(/Latihan/)).not.toBeInTheDocument();
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
            stravaSync: { state: 'ready', last_synced_at: '2026-07-04T00:00:00Z' },
        });
        render(<Aku identity={identity} stats={stats} />);
        expect(screen.queryByText(/Sambungin lagi/)).not.toBeInTheDocument();
    });

    it('shows a persistent reconnect CTA when the Strava connection is revoked', () => {
        setMockPage({
            auth: { user: makeUser() },
            flash: {},
            demoLoginEnabled: false,
            stravaSync: { state: 'revoked', last_synced_at: null },
        });
        render(<Aku identity={identity} stats={stats} />);
        const link = screen.getByText('Sambungin lagi').closest('a');
        expect(link).toHaveAttribute('href', '/auth/strava/redirect?from=/profil');
    });

    it('renders the profile voice quote when profileVoice is provided', () => {
        const profileVoice = {
            id: 3,
            status: 'done' as const,
            content: 'Kamu makin konsisten tiap minggu.',
            type: 'aku_profile_voice' as const,
            subject_type: 'user',
            subject_id: 1,
            discriminator: null,
        };
        render(<Aku identity={identity} stats={stats} profileVoice={profileVoice} />);
        expect(screen.getByText(/Kamu makin konsisten tiap minggu/)).toBeInTheDocument();
    });
});
