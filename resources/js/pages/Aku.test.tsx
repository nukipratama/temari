import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { router } from '@inertiajs/react';
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

    it('shows the connect link when Telegram is not connected', () => {
        const telegram = {
            connected: false,
            username: null,
            connect_url: 'https://t.me/temari_bot?start=tok',
            notify_post_run: true,
            notify_weekly_recap: true,
            notify_monthly_recap: true,
            notify_daily_briefing: false,
        };
        render(<Aku identity={identity} stats={stats} telegram={telegram} />);
        const link = screen.getByText('Telegram').closest('a');
        expect(link).toHaveAttribute('href', 'https://t.me/temari_bot?start=tok');
    });

    it('shows the demo modal when a demo user taps the Telegram row', () => {
        setMockPage({
            auth: { user: makeUser({ is_demo: true }) },
            flash: {},
            demoLoginEnabled: false,
        });
        const telegram = {
            connected: false,
            username: null,
            connect_url: 'https://t.me/temari_bot?start=tok',
            notify_post_run: true,
            notify_weekly_recap: true,
            notify_monthly_recap: true,
            notify_daily_briefing: false,
        };
        render(<Aku identity={identity} stats={stats} telegram={telegram} />);

        // Not an anchor (can't deep-link the shared bot)
        expect(screen.getByText('Telegram').closest('a')).toBeNull();
        fireEvent.click(screen.getByText('Telegram'));
        expect(screen.getByText('Telegram-nya lagi istirahat dulu')).toBeInTheDocument();
    });

    it('shows the preference toggles when Telegram is connected', () => {
        const telegram = {
            connected: true,
            username: 'ada_runs',
            connect_url: null,
            notify_post_run: true,
            notify_weekly_recap: false,
            notify_monthly_recap: true,
            notify_daily_briefing: false,
        };
        render(<Aku identity={identity} stats={stats} telegram={telegram} />);
        expect(screen.getByText(/Telegram aktif/)).toBeInTheDocument();
        expect(screen.getByRole('switch', { name: 'Cerita abis lari' })).toHaveAttribute('aria-checked', 'true');
        expect(screen.getByRole('switch', { name: 'Rekap mingguan' })).toHaveAttribute('aria-checked', 'false');
        expect(screen.getByRole('switch', { name: 'Rekap bulanan' })).toHaveAttribute('aria-checked', 'true');
        expect(screen.getByRole('switch', { name: 'Ringkasan harian' })).toHaveAttribute('aria-checked', 'false');
    });

    it('patches preferences when a toggle is flipped, carrying all current values', () => {
        vi.mocked(router.patch).mockReset();
        const telegram = {
            connected: true,
            username: null,
            connect_url: null,
            notify_post_run: true,
            notify_weekly_recap: false,
            notify_monthly_recap: true,
            notify_daily_briefing: false,
        };
        render(<Aku identity={identity} stats={stats} telegram={telegram} />);

        fireEvent.click(screen.getByRole('switch', { name: 'Rekap mingguan' }));

        expect(router.patch).toHaveBeenCalledWith(
            '/profil/telegram',
            { notify_post_run: true, notify_weekly_recap: true, notify_monthly_recap: true, notify_daily_briefing: false },
            { preserveScroll: true },
        );
    });

    it('patches the monthly recap flag when its toggle is flipped', () => {
        vi.mocked(router.patch).mockReset();
        const telegram = {
            connected: true,
            username: null,
            connect_url: null,
            notify_post_run: true,
            notify_weekly_recap: true,
            notify_monthly_recap: true,
            notify_daily_briefing: false,
        };
        render(<Aku identity={identity} stats={stats} telegram={telegram} />);

        fireEvent.click(screen.getByRole('switch', { name: 'Rekap bulanan' }));

        expect(router.patch).toHaveBeenCalledWith(
            '/profil/telegram',
            { notify_post_run: true, notify_weekly_recap: true, notify_monthly_recap: false, notify_daily_briefing: false },
            { preserveScroll: true },
        );
    });

    it('patches the daily briefing flag when its toggle is flipped', () => {
        vi.mocked(router.patch).mockReset();
        const telegram = {
            connected: true,
            username: null,
            connect_url: null,
            notify_post_run: true,
            notify_weekly_recap: true,
            notify_monthly_recap: true,
            notify_daily_briefing: false,
        };
        render(<Aku identity={identity} stats={stats} telegram={telegram} />);

        fireEvent.click(screen.getByRole('switch', { name: 'Ringkasan harian' }));

        expect(router.patch).toHaveBeenCalledWith(
            '/profil/telegram',
            { notify_post_run: true, notify_weekly_recap: true, notify_monthly_recap: true, notify_daily_briefing: true },
            { preserveScroll: true },
        );
    });

    it('disconnects via DELETE when Putuskan is clicked', () => {
        vi.mocked(router.delete).mockReset();
        const telegram = {
            connected: true,
            username: null,
            connect_url: null,
            notify_post_run: true,
            notify_weekly_recap: true,
            notify_monthly_recap: true,
            notify_daily_briefing: false,
        };
        render(<Aku identity={identity} stats={stats} telegram={telegram} />);

        fireEvent.click(screen.getByText('Putuskan'));

        expect(router.delete).toHaveBeenCalledWith('/profil/telegram', { preserveScroll: true });
    });

    it('posts a test notification when "Kirim notifikasi tes" is clicked', () => {
        vi.mocked(router.post).mockReset();
        const telegram = {
            connected: true,
            username: null,
            connect_url: null,
            notify_post_run: true,
            notify_weekly_recap: true,
            notify_monthly_recap: true,
            notify_daily_briefing: false,
        };
        render(<Aku identity={identity} stats={stats} telegram={telegram} />);

        fireEvent.click(screen.getByText('Kirim notifikasi tes'));

        expect(router.post).toHaveBeenCalledWith('/profil/telegram/test', {}, { preserveScroll: true });
    });

    it('opens the demo-blocked modal instead of patching when a demo user flips a toggle', () => {
        setMockPage({
            auth: { user: makeUser({ is_demo: true }) },
            flash: {},
            demoLoginEnabled: false,
        });
        vi.mocked(router.patch).mockReset();
        const telegram = {
            connected: true,
            username: null,
            connect_url: null,
            notify_post_run: true,
            notify_weekly_recap: false,
            notify_monthly_recap: true,
            notify_daily_briefing: false,
        };
        render(<Aku identity={identity} stats={stats} telegram={telegram} />);

        const toggle = screen.getByRole('switch', { name: 'Rekap mingguan' });
        fireEvent.click(toggle);

        expect(router.patch).not.toHaveBeenCalled();
        // Local state doesn't flip either, so the UI stays honest about what happened.
        expect(toggle).toHaveAttribute('aria-checked', 'false');
        expect(screen.getByText('Telegram-nya lagi istirahat dulu')).toBeInTheDocument();
    });

    it('opens the demo-blocked modal instead of disconnecting for a demo user', () => {
        setMockPage({
            auth: { user: makeUser({ is_demo: true }) },
            flash: {},
            demoLoginEnabled: false,
        });
        vi.mocked(router.delete).mockReset();
        const telegram = {
            connected: true,
            username: null,
            connect_url: null,
            notify_post_run: true,
            notify_weekly_recap: true,
            notify_monthly_recap: true,
            notify_daily_briefing: false,
        };
        render(<Aku identity={identity} stats={stats} telegram={telegram} />);

        fireEvent.click(screen.getByText('Putuskan'));

        expect(router.delete).not.toHaveBeenCalled();
        expect(screen.getByText('Telegram-nya lagi istirahat dulu')).toBeInTheDocument();
    });

    it('opens the demo-blocked modal instead of posting a test notification for a demo user', () => {
        setMockPage({
            auth: { user: makeUser({ is_demo: true }) },
            flash: {},
            demoLoginEnabled: false,
        });
        vi.mocked(router.post).mockReset();
        const telegram = {
            connected: true,
            username: null,
            connect_url: null,
            notify_post_run: true,
            notify_weekly_recap: true,
            notify_monthly_recap: true,
            notify_daily_briefing: false,
        };
        render(<Aku identity={identity} stats={stats} telegram={telegram} />);

        fireEvent.click(screen.getByText('Kirim notifikasi tes'));

        expect(router.post).not.toHaveBeenCalled();
        expect(screen.getByText('Telegram-nya lagi istirahat dulu')).toBeInTheDocument();
    });

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
        expect(link).toHaveAttribute('href', '/auth/strava/redirect');
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

    it('closes the demo-blocked modal from the disconnected-Telegram state', async () => {
        setMockPage({
            auth: { user: makeUser({ is_demo: true }) },
            flash: {},
            demoLoginEnabled: false,
        });
        const telegram = {
            connected: false,
            username: null,
            connect_url: 'https://t.me/temari_bot?start=tok',
            notify_post_run: true,
            notify_weekly_recap: true,
            notify_monthly_recap: true,
            notify_daily_briefing: false,
        };
        render(<Aku identity={identity} stats={stats} telegram={telegram} />);

        fireEvent.click(screen.getByText('Telegram'));
        expect(screen.getByText('Telegram-nya lagi istirahat dulu')).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'Nanti aja' }));
        await waitFor(() => {
            expect(screen.queryByText('Telegram-nya lagi istirahat dulu')).not.toBeInTheDocument();
        });
    });

    it('closes the demo-blocked modal from the connected-Telegram state', async () => {
        setMockPage({
            auth: { user: makeUser({ is_demo: true }) },
            flash: {},
            demoLoginEnabled: false,
        });
        const telegram = {
            connected: true,
            username: null,
            connect_url: null,
            notify_post_run: true,
            notify_weekly_recap: true,
            notify_monthly_recap: true,
            notify_daily_briefing: false,
        };
        render(<Aku identity={identity} stats={stats} telegram={telegram} />);

        fireEvent.click(screen.getByText('Putuskan'));
        expect(screen.getByText('Telegram-nya lagi istirahat dulu')).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'Nanti aja' }));
        await waitFor(() => {
            expect(screen.queryByText('Telegram-nya lagi istirahat dulu')).not.toBeInTheDocument();
        });
    });
});
