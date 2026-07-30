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

    // Settings moved out of this page into the avatar menu, next to logout, so
    // it is reachable from every page rather than only from here. The entry
    // point is asserted in UserMenu.test.tsx.
    it('no longer carries a settings row of its own', () => {
        render(<Aku identity={identity} stats={stats} />);
        expect(screen.queryByText(/Notifikasi Telegram, zona HR/)).not.toBeInTheDocument();
    });

    it('renders the persona bar without a narration block of its own', () => {
        const mix = [
            { mood: 'enteng' as const, count: 22, percent: 34.9 },
            { mood: 'adem' as const, count: 21, percent: 33.3 },
        ];
        render(<Aku identity={identity} stats={stats} personaMix={mix} />);
        expect(screen.getByText(/Persona/)).toBeInTheDocument();
        expect(screen.queryByText(/Belum dibaca Temari/)).not.toBeInTheDocument();
    });

    // The persona bar moved into the hero panel (it's the data behind "Kata
    // Temari"'s read on you) instead of its own section below — a standalone
    // <section class="mt-10"> would mean it slipped back out of the hero.
    it('renders the persona bar inside the hero panel, not a separate section below it', () => {
        const mix = [{ mood: 'nyala' as const, count: 3, percent: 100 }];
        render(<Aku identity={identity} stats={stats} personaMix={mix} />);
        expect(screen.getByText(/Persona/).closest('section')).toBeNull();
    });

    it('shows the "Bareng Temari sejak" join date when member_since is present', () => {
        render(<Aku identity={identity} stats={stats} />);
        expect(screen.getByText('Bareng Temari sejak')).toBeInTheDocument();
        expect(screen.getByText('12 Agu 2024')).toBeInTheDocument();
    });

    it('omits the join-date block when member_since is missing', () => {
        render(<Aku identity={{ ...identity, member_since: null }} stats={stats} />);
        expect(screen.queryByText('Bareng Temari sejak')).not.toBeInTheDocument();
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

    it('renders no VDOT, threshold pace or Latihan pace block', () => {
        render(<Aku identity={identity} stats={stats} />);
        expect(screen.queryByText('VDOT')).not.toBeInTheDocument();
        expect(screen.queryByText('Threshold pace')).not.toBeInTheDocument();
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
            subject_type: 'aku_profile_voice_user',
            subject_id: 1,
            discriminator: '2026-W21',
        };
        render(<Aku identity={identity} stats={stats} profileVoice={profileVoice} />);
        expect(screen.getByText(/Kamu makin konsisten tiap minggu/)).toBeInTheDocument();
    });
});
